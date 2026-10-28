<?php

namespace SocialDept\AtpSpaces\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpSpaces\Support\Cid;
use SocialDept\AtpSpaces\Support\Drisl\Bytes;
use SocialDept\AtpSpaces\Support\Drisl\Drisl;
use SocialDept\AtpSpaces\Support\StructuredFields;

class DrislTest extends TestCase
{
    /**
     * A post from a public repo (did:plc:eob75vcjtmbaef2tn4evc4sl, rkey
     * 3myeci6duy22n), verbatim, with the CID its PDS reported. Its thumbnail
     * is a blob link, so it covers `$link` too.
     */
    public function test_a_real_record_encodes_to_its_cid(): void
    {
        $json = <<<'JSON'
            {"text":"me: *kicks php*","$type":"app.bsky.feed.post","embed":{"$type":"app.bsky.embed.external","external":{"uri":"https://static.klipy.com/ii/4e7bea9f7a3371424e6c16ebc93252fe/62/c2/5BbqI5cEwJXwLH1z5cd.gif?hh=178&ww=332&mp4=amcFGff6fB2pWuj&webm=rkfJ7IiLp88H4mO5","thumb":{"ref":{"$link":"bafkreicy5rffioopatlxaxr2s3nr2cdc4k3ar5fmffdeiqwegsgqx5plsq"},"size":34270,"$type":"blob","mimeType":"image/jpeg"},"title":"Baby Elephant Stumbles and Gets Up","description":"Alt: Baby Elephant Stumbles and Gets Up"}},"langs":["en"],"createdAt":"2026-10-21T04:23:34.873Z"}
            JSON;

        $this->assertSame(
            'bafyreif24zeh77s6ku4doaoorf4dvqko4zk43darj66obw2272mh5qskei',
            (string) Cid::forBytes(Drisl::encode(Drisl::fromJson($json))),
        );
    }

    public function test_it_round_trips_every_type_exactly(): void
    {
        $value = Drisl::fromJson('{"text":"hi","n":-5,"big":70000,"ok":true,"none":null,"list":[1,"a"],"empty":{},"bytes":{"$bytes":"AAEC"},"link":{"$link":"bafkreicy5rffioopatlxaxr2s3nr2cdc4k3ar5fmffdeiqwegsgqx5plsq"}}');
        $encoded = Drisl::encode($value);

        $this->assertSame($encoded, Drisl::encode(Drisl::decode($encoded)));
        $this->assertInstanceOf(Bytes::class, Drisl::decode($encoded)->bytes);
        $this->assertInstanceOf(Cid::class, Drisl::decode($encoded)->link);
    }

    public function test_map_keys_sort_by_length_then_bytes(): void
    {
        $this->assertSame(
            ['a', 'b', 'aa'],
            array_keys(get_object_vars(Drisl::decode(Drisl::encode(['aa' => 1, 'b' => 2, 'a' => 3])))),
        );
    }

    public function test_floats_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Drisl::encode(['n' => 1.5]);
    }

    public function test_truncated_input_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Drisl::decode(substr(Drisl::encode(['text' => 'hello']), 0, -2));
    }

    public function test_a_cid_round_trips_through_its_string_form(): void
    {
        $cid = Cid::forBytes('x');

        $this->assertTrue(Cid::parse((string) $cid)->equals($cid));
        $this->assertStringStartsWith('bafyrei', (string) $cid);
    }

    public function test_it_reads_a_mac_header(): void
    {
        $fields = StructuredFields::parseDictionary('type="atproto-space-repo", ver=1, mac=:AAECAw==:');

        $this->assertSame('atproto-space-repo', $fields['type']);
        $this->assertSame(1, $fields['ver']);
        $this->assertSame("\x00\x01\x02\x03", $fields['mac']->value);
    }

    public function test_it_tolerates_missing_padding_and_drops_parameters(): void
    {
        $fields = StructuredFields::parseDictionary('mac=:AAECAw:;x=1, type="a\"b"');

        $this->assertSame("\x00\x01\x02\x03", $fields['mac']->value);
        $this->assertSame('a"b', $fields['type']);
    }

    public function test_an_inner_list_refuses_the_field(): void
    {
        $this->assertNull(StructuredFields::parseDictionary('type=("a" "b")'));
    }
}
