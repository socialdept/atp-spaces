<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Exceptions\RepoVerificationException;
use SocialDept\AtpSpaces\Repo\Commit;
use SocialDept\AtpSpaces\Repo\Mac;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\Support\Cid;

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

    public function test_matches_compares_against_a_commit_digest(): void
    {
        $repo = (new RepoCommit())->add('com.example.thread', '1', 'bafyA');

        $this->assertTrue($repo->matches($this->commit($repo->digest())));
        $this->assertFalse($repo->matches($this->commit(str_repeat("\0", 32))));
    }

    /**
     * Pinned to values computed with Node's crypto, so the MAC context cannot
     * drift from the upstream format unnoticed.
     */
    public function test_the_commit_mac_matches_an_independent_vector(): void
    {
        $commit = new Commit(1, 'did:plc:member', self::SPACE, self::REV, str_repeat("\x09", 32));

        $this->assertSame(
            '3f883686c9a14bd0ec37657428bef4f795d75038208d2c10bd602517bd339bb8',
            bin2hex(Mac::forCommit(str_repeat("\x07", 32), $commit)),
        );
    }

    public function test_the_record_mac_matches_an_independent_vector(): void
    {
        $this->assertSame(
            'ee73fadd929e67296481af74cff016248549d3aafa35525e6f82b14618be3d6c',
            bin2hex(Mac::forRecord(str_repeat("\x07", 32), self::SPACE.'/did:plc:member/com.example.thread/3abc', Cid::forBytes('x'))),
        );
    }

    public function test_a_commit_with_its_mac_verifies(): void
    {
        $commit = $this->commit(str_repeat("\x01", 32));
        $mac = Mac::forCommit($this->secret(), $commit);

        $this->assertTrue(RepoCommit::verify($commit, self::SPACE, self::AUTHOR, $this->secret(), $mac)->isVerified());
    }

    public function test_another_secret_fails_the_mac(): void
    {
        $commit = $this->commit(str_repeat("\x01", 32));
        $mac = Mac::forCommit($this->secret(), $commit);

        $result = RepoCommit::verify($commit, self::SPACE, self::AUTHOR, str_repeat("\xFF", 32), $mac);

        $this->assertFalse($result->macValid);
        $this->assertSame('commit mac does not match', $result->failureReason());
    }

    public function test_tampered_data_fails_the_mac(): void
    {
        $commit = $this->commit(str_repeat("\x01", 32));
        $mac = Mac::forCommit($this->secret(), $commit);

        $this->assertFalse(RepoCommit::verify($this->commit(str_repeat("\x02", 32)), self::SPACE, self::AUTHOR, $this->secret(), $mac)->isVerified());
    }

    /**
     * A host answering for another repo or space, even with a valid MAC over
     * what it sent, is not answering the question asked.
     */
    public function test_a_commit_for_another_repo_or_space_fails(): void
    {
        $commit = $this->commit(str_repeat("\x01", 32));
        $mac = Mac::forCommit($this->secret(), $commit);

        $this->assertSame('commit is for another repo', RepoCommit::verify($commit, self::SPACE, 'did:plc:someone-else', $this->secret(), $mac)->failureReason());
        $this->assertSame('commit is for another space', RepoCommit::verify($commit, self::SPACE.'-other', self::AUTHOR, $this->secret(), $mac)->failureReason());
    }

    public function test_an_unsupported_version_fails(): void
    {
        $commit = new Commit(2, self::AUTHOR, self::SPACE, self::REV, str_repeat("\x01", 32));
        $mac = Mac::forCommit($this->secret(), $commit);

        $this->assertSame('unsupported commit version', RepoCommit::verify($commit, self::SPACE, self::AUTHOR, $this->secret(), $mac)->failureReason());
    }

    public function test_it_can_assert_instead_of_returning(): void
    {
        $commit = $this->commit(str_repeat("\x01", 32));

        $this->expectException(RepoVerificationException::class);
        $this->expectExceptionMessage('commit mac does not match');

        RepoCommit::verify($commit, self::SPACE, self::AUTHOR, $this->secret(), str_repeat("\0", 32))->assertVerified();
    }

    protected function commit(string $data): Commit
    {
        return new Commit(Commit::VERSION, self::AUTHOR, self::SPACE, self::REV, $data);
    }

    protected function secret(): string
    {
        return str_repeat("\x07", 32);
    }
}
