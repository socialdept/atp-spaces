<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Scopes\SpaceAction;
use SocialDept\AtpSpaces\Scopes\SpaceManageOp;
use SocialDept\AtpSpaces\Scopes\SpaceScope;
use SocialDept\AtpSpaces\SpaceRef;

class SpaceScopeTest extends TestCase
{
    public function test_the_bare_form_omits_every_default(): void
    {
        $this->assertSame('space:com.example.forum', (string) SpaceScope::for('com.example.forum'));
    }

    public function test_a_concrete_space_names_authority_and_skey(): void
    {
        $scope = SpaceScope::forSpace(
            SpaceRef::make('did:plc:abc', 'com.example.forum', 'main')
        );

        $this->assertSame(
            'space:com.example.forum?authority=did:plc:abc&skey=main',
            (string) $scope,
        );
    }

    /**
     * DIDs must stay readable: the colon is one of the characters a scope
     * string leaves literal rather than percent-encoding.
     */
    public function test_a_did_keeps_its_colons(): void
    {
        $scope = SpaceScope::for('com.example.forum')->authority('did:plc:3znwyhr2wogfy4nyhy4daii4');

        $this->assertStringContainsString('authority=did:plc:3znwyhr2wogfy4nyhy4daii4', (string) $scope);
    }

    public function test_read_only_narrows_the_actions(): void
    {
        $this->assertSame(
            'space:com.example.forum?action=read',
            (string) SpaceScope::for('com.example.forum')->readOnly(),
        );
    }

    public function test_read_self_is_the_narrowest_action(): void
    {
        $this->assertSame(
            'space:com.example.forum?action=read_self',
            (string) SpaceScope::for('com.example.forum')->readSelfOnly(),
        );
    }

    public function test_the_default_action_set_is_omitted(): void
    {
        $scope = SpaceScope::for('com.example.forum')->actions(SpaceAction::defaults());

        $this->assertSame('space:com.example.forum', (string) $scope);
    }

    public function test_actions_are_emitted_in_canonical_order(): void
    {
        $scope = SpaceScope::for('com.example.forum')
            ->actions([SpaceAction::Delete, SpaceAction::Read, SpaceAction::Create]);

        $this->assertSame(
            'space:com.example.forum?action=read&action=create&action=delete',
            (string) $scope,
        );
    }

    public function test_collections_are_deduped_and_sorted(): void
    {
        $scope = SpaceScope::for('com.example.forum')
            ->collections(['com.example.reply', 'com.example.thread', 'com.example.reply']);

        $this->assertSame(
            'space:com.example.forum?collection=com.example.reply&collection=com.example.thread',
            (string) $scope,
        );
    }

    public function test_a_wildcard_absorbs_other_collections(): void
    {
        $scope = SpaceScope::for('com.example.forum')
            ->collections(['com.example.thread', '*']);

        $this->assertSame('space:com.example.forum?collection=*', (string) $scope);
    }

    public function test_management_operations_are_appended(): void
    {
        $scope = SpaceScope::for('com.example.forum')
            ->manage([SpaceManageOp::Delete, SpaceManageOp::Create]);

        $this->assertSame(
            'space:com.example.forum?manage=create&manage=delete',
            (string) $scope,
        );
    }

    public function test_parameters_are_emitted_in_schema_order(): void
    {
        $scope = SpaceScope::for('com.example.forum')
            ->authority('did:plc:abc')
            ->skey('main')
            ->collections('com.example.thread')
            ->actions(SpaceAction::Create)
            ->manage(SpaceManageOp::Update);

        $this->assertSame(
            'space:com.example.forum'
                .'?authority=did:plc:abc'
                .'&skey=main'
                .'&collection=com.example.thread'
                .'&action=create'
                .'&manage=update',
            (string) $scope,
        );
    }

    public function test_a_wildcard_type_is_allowed(): void
    {
        $this->assertSame('space:*', (string) SpaceScope::for('*'));
    }

    public function test_the_builder_is_immutable(): void
    {
        $base = SpaceScope::for('com.example.forum');
        $narrowed = $base->readOnly();

        $this->assertSame('space:com.example.forum', (string) $base);
        $this->assertNotSame((string) $base, (string) $narrowed);
    }

    public function test_read_implies_read_self(): void
    {
        $this->assertTrue(SpaceAction::Read->isRead());
        $this->assertTrue(SpaceAction::ReadSelf->isRead());
        $this->assertFalse(SpaceAction::Create->isRead());
        $this->assertNotContains(SpaceAction::ReadSelf, SpaceAction::defaults());
    }
}
