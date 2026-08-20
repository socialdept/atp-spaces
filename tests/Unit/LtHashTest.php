<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Repo\LtHash;

class LtHashTest extends TestCase
{
    public function test_a_fresh_hash_is_empty(): void
    {
        $hash = new LtHash();

        $this->assertTrue($hash->isEmpty());
        $this->assertSame(LtHash::STATE_BYTES, strlen($hash->state()));
        $this->assertSame(str_repeat("\0", LtHash::STATE_BYTES), $hash->state());
    }

    /**
     * The property the whole sync model rests on: the state depends on the set,
     * not on the order elements arrived in.
     */
    public function test_it_is_order_independent(): void
    {
        $elements = ['a/1/cidA', 'b/2/cidB', 'c/3/cidC', 'd/4/cidD'];

        $forward = new LtHash();

        foreach ($elements as $element) {
            $forward->add($element);
        }

        $backward = new LtHash();

        foreach (array_reverse($elements) as $element) {
            $backward->add($element);
        }

        $shuffled = new LtHash();

        foreach ([$elements[2], $elements[0], $elements[3], $elements[1]] as $element) {
            $shuffled->add($element);
        }

        $this->assertTrue($forward->equals($backward));
        $this->assertTrue($forward->equals($shuffled));
        $this->assertSame($forward->digest(), $shuffled->digest());
    }

    public function test_removing_an_element_undoes_adding_it(): void
    {
        $hash = new LtHash();

        $hash->add('app.bsky.feed.post/3abc/bafyone');
        $this->assertFalse($hash->isEmpty());

        $hash->remove('app.bsky.feed.post/3abc/bafyone');
        $this->assertTrue($hash->isEmpty());
    }

    public function test_removal_commutes_with_addition(): void
    {
        $removeFirst = (new LtHash())
            ->remove('gone/1/cid')
            ->add('kept/2/cid')
            ->add('gone/1/cid');

        $addFirst = (new LtHash())
            ->add('kept/2/cid');

        $this->assertTrue($removeFirst->equals($addFirst));
    }

    public function test_state_round_trips(): void
    {
        $original = (new LtHash())->add('a/1/x')->add('b/2/y');
        $restored = LtHash::fromState($original->state());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->digest(), $restored->digest());
    }

    public function test_the_digest_is_sha256_of_the_state(): void
    {
        $hash = (new LtHash())->add('a/1/x');

        $this->assertSame(hash('sha256', $hash->state(), true), $hash->digest());
        $this->assertSame(32, strlen($hash->digest()));
    }

    public function test_a_copy_does_not_share_state(): void
    {
        $original = (new LtHash())->add('a/1/x');
        $copy = $original->copy();

        $copy->add('b/2/y');

        $this->assertFalse($original->equals($copy));
    }

    public function test_it_rejects_a_state_of_the_wrong_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LtHash(str_repeat("\0", 100));
    }

    public function test_different_sets_differ(): void
    {
        $a = (new LtHash())->add('a/1/x');
        $b = (new LtHash())->add('a/1/y');

        $this->assertFalse($a->equals($b));
    }
}
