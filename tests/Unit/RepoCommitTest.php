<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Contracts\SignatureVerifier;
use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Repo\CommitContext;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\Repo\SignedCommit;

class RepoCommitTest extends TestCase
{
    protected const SPACE = 'at://did:plc:authority/space/com.example.forum/main';
    protected const AUTHOR = 'did:plc:author';
    protected const REV = '3lkq2xhbc2s2j';

    public function test_the_element_format_matches_the_spec(): void
    {
        $this->assertSame(
            'app.bsky.feed.post/3abc/bafyrei',
            RepoCommit::element('app.bsky.feed.post', '3abc', 'bafyrei'),
        );
    }

    public function test_an_index_and_a_record_list_agree(): void
    {
        $records = [
            ['collection' => 'com.example.thread', 'rkey' => '1', 'cid' => 'bafyA'],
            ['collection' => 'com.example.reply', 'rkey' => '2', 'cid' => 'bafyB'],
        ];

        $index = [
            'com.example.thread/1' => 'bafyA',
            'com.example.reply/2' => 'bafyB',
        ];

        $this->assertSame(
            RepoCommit::fromRecords($records)->digest(),
            RepoCommit::fromIndex($index)->digest(),
        );
    }

    public function test_applying_a_create_adds_the_record(): void
    {
        $commit = new RepoCommit();
        $commit->applyOp(new RepoOp('com.example.thread', '1', cid: 'bafyA'));

        $this->assertSame(
            RepoCommit::fromIndex(['com.example.thread/1' => 'bafyA'])->digest(),
            $commit->digest(),
        );
    }

    public function test_applying_an_update_replaces_the_record(): void
    {
        $commit = RepoCommit::fromIndex(['com.example.thread/1' => 'bafyA']);
        $commit->applyOp(new RepoOp('com.example.thread', '1', cid: 'bafyB', prev: 'bafyA'));

        $this->assertSame(
            RepoCommit::fromIndex(['com.example.thread/1' => 'bafyB'])->digest(),
            $commit->digest(),
        );
    }

    public function test_applying_a_delete_removes_the_record(): void
    {
        $commit = RepoCommit::fromIndex([
            'com.example.thread/1' => 'bafyA',
            'com.example.thread/2' => 'bafyB',
        ]);

        $commit->applyOp(new RepoOp('com.example.thread', '1', prev: 'bafyA'));

        $this->assertSame(
            RepoCommit::fromIndex(['com.example.thread/2' => 'bafyB'])->digest(),
            $commit->digest(),
        );
    }

    public function test_an_oplog_replays_in_any_order(): void
    {
        $ops = [
            new RepoOp('com.example.thread', '1', cid: 'bafyA'),
            new RepoOp('com.example.thread', '2', cid: 'bafyB'),
            new RepoOp('com.example.reply', '3', cid: 'bafyC'),
        ];

        $forward = (new RepoCommit())->applyOps($ops);
        $reversed = (new RepoCommit())->applyOps(array_reverse($ops));

        $this->assertSame($forward->digest(), $reversed->digest());
    }

    public function test_op_kinds_are_distinguished_by_shape(): void
    {
        $create = new RepoOp('c', 'r', cid: 'new');
        $update = new RepoOp('c', 'r', cid: 'new', prev: 'old');
        $delete = new RepoOp('c', 'r', prev: 'old');

        $this->assertTrue($create->isCreate());
        $this->assertTrue($update->isUpdate());
        $this->assertTrue($delete->isDelete());
        $this->assertSame('c/r', $create->path());
    }

    public function test_matches_compares_against_a_commit_hash(): void
    {
        $commit = RepoCommit::fromIndex(['com.example.thread/1' => 'bafyA']);
        $signed = $this->sign($commit->digest());

        $this->assertTrue($commit->matches($signed));
        $this->assertFalse(RepoCommit::fromIndex([])->matches($signed));
    }

    public function test_the_mac_binds_the_hash_to_the_context(): void
    {
        $commit = RepoCommit::fromIndex(['com.example.thread/1' => 'bafyA']);
        $signed = $this->sign($commit->digest());

        $result = RepoCommit::verify($signed, $this->context());

        $this->assertTrue($result->isIntact());
        $this->assertTrue($result->macValid);

        // Integrity without a verifier is not full verification.
        $this->assertFalse($result->isFullyVerified());
        $this->assertFalse($result->signatureChecked);
        $this->assertSame('commit signature was not checked', $result->failureReason());
    }

    public function test_a_commit_moved_to_another_space_fails_the_mac(): void
    {
        $signed = $this->sign(RepoCommit::fromIndex(['a/1' => 'bafyA'])->digest());

        $elsewhere = new CommitContext(
            'at://did:plc:authority/space/com.example.forum/other',
            self::AUTHOR,
            self::REV,
        );

        $this->assertFalse(RepoCommit::verify($signed, $elsewhere)->macValid);
    }

    public function test_a_tampered_hash_fails_the_mac(): void
    {
        $signed = $this->sign(RepoCommit::fromIndex(['a/1' => 'bafyA'])->digest());

        $tampered = new SignedCommit(
            $signed->ver,
            hash('sha256', 'something else', true),
            $signed->ikm,
            $signed->sig,
            $signed->mac,
            $signed->rev,
        );

        $this->assertFalse(RepoCommit::verify($tampered, $this->context())->macValid);
    }

    public function test_a_mismatched_revision_is_rejected(): void
    {
        $signed = $this->sign(RepoCommit::fromIndex(['a/1' => 'bafyA'])->digest());

        $other = new CommitContext(self::SPACE, self::AUTHOR, '3different0000');

        $result = RepoCommit::verify($signed, $other);

        $this->assertFalse($result->revisionMatches);
        $this->assertSame('commit revision does not match the context', $result->failureReason());
    }

    public function test_an_unsupported_version_is_rejected(): void
    {
        $signed = $this->sign(RepoCommit::fromIndex(['a/1' => 'bafyA'])->digest(), version: 99);

        $result = RepoCommit::verify($signed, $this->context());

        $this->assertFalse($result->versionSupported);
        $this->assertSame('unsupported commit version', $result->failureReason());
    }

    public function test_a_verifier_establishes_authenticity(): void
    {
        $commit = RepoCommit::fromIndex(['a/1' => 'bafyA']);
        $signed = $this->sign($commit->digest());

        $accepting = new class () implements SignatureVerifier {
            public function verify(string $didKey, string $data, string $signature): bool
            {
                return true;
            }
        };

        $result = RepoCommit::verify($signed, $this->context(), $accepting, 'did:key:zQ3sh');

        $this->assertTrue($result->isFullyVerified());
        $this->assertNull($result->failureReason());
    }

    public function test_a_rejecting_verifier_fails_the_commit(): void
    {
        $signed = $this->sign(RepoCommit::fromIndex(['a/1' => 'bafyA'])->digest());

        $rejecting = new class () implements SignatureVerifier {
            public function verify(string $didKey, string $data, string $signature): bool
            {
                return false;
            }
        };

        $result = RepoCommit::verify($signed, $this->context(), $rejecting, 'did:key:zQ3sh');

        $this->assertTrue($result->isIntact());
        $this->assertFalse($result->isFullyVerified());
        $this->assertSame('commit signature is invalid', $result->failureReason());
    }

    public function test_it_can_assert_instead_of_returning(): void
    {
        $commit = RepoCommit::fromIndex(['a/1' => 'bafyA']);
        $result = RepoCommit::verify($this->sign($commit->digest()), $this->context());

        // Integrity passes, so this is a no-op.
        $result->assertIntact();

        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage('commit signature was not checked');

        $result->assertFullyVerified();
    }

    protected function context(): CommitContext
    {
        return new CommitContext(self::SPACE, self::AUTHOR, self::REV);
    }

    /**
     * Build a commit whose MAC is correct, standing in for a PDS.
     */
    protected function sign(string $hash, int $version = SignedCommit::VERSION): SignedCommit
    {
        $ikm = random_bytes(SignedCommit::IKM_BYTES);
        $contextBytes = $this->context()->encode($ikm);

        return new SignedCommit(
            $version,
            $hash,
            $ikm,
            'not-a-real-signature',
            RepoCommit::computeMac($ikm, $contextBytes, $hash),
            self::REV,
        );
    }
}
