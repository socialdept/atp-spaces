<?php

namespace SocialDept\AtpSpaces\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use SocialDept\AtpSpaces\Tests\TestCase;

/**
 * The network half of the drift check.
 *
 * Grouped as integration because it calls the GitHub API, which is rate
 * limited and unavailable offline. CI excludes it; run it deliberately:
 *
 *     vendor/bin/phpunit --group integration
 *
 * A failure here is not a bug in this package — it means upstream moved and
 * the vendored lexicons need re-pinning.
 */
#[Group('integration')]
class LexiconDriftTest extends TestCase
{
    public function test_the_vendored_lexicons_match_upstream(): void
    {
        $this->artisan('spaces:lexicon-drift')->assertSuccessful();
    }
}
