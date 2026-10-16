<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Dot.Central must stay customer-neutral: no customer, site, pit, crew or
 * person from any source study, and no real mine names as demo data. The
 * excluded terms are base64-encoded so that this file never contains them
 * in plain text. Add a term by appending base64_encode(strtolower($term)).
 */
class ProhibitedReferencesTest extends TestCase
{
    /** @var list<string> */
    private const ENCODED_TERMS = [
        'YWZyaWNvYWw=',
        'a2xpcGZvbnRlaW4=',
        'bmV3IGxhcmdv',
        'a29sb21lbGE=',
    ];

    /** @var list<string> */
    private const SCANNED_DIRECTORIES = [
        'app', 'config', 'database', 'resources', 'routes', 'tests', 'docs/dot-central',
    ];

    public function test_repository_contains_no_prohibited_customer_references(): void
    {
        $terms = array_map(
            fn (string $encoded): string => (string) base64_decode($encoded, true),
            self::ENCODED_TERMS,
        );

        $finder = Finder::create()
            ->files()
            ->in(array_map(fn (string $dir): string => base_path($dir), self::SCANNED_DIRECTORIES))
            ->exclude(['vendor', 'node_modules'])
            ->name(['*.php', '*.md', '*.json', '*.js', '*.css', '*.yml', '*.yaml', '*.txt', '*.env', '*.example']);

        $hits = [];

        foreach ($finder as $file) {
            if ($file->getRealPath() === __FILE__) {
                continue;
            }

            $contents = strtolower((string) file_get_contents($file->getRealPath()));

            foreach ($terms as $term) {
                if (str_contains($contents, $term)) {
                    $hits[] = $file->getRelativePathname().' contains an excluded term';
                }
            }
        }

        $this->assertSame([], $hits, implode(PHP_EOL, $hits));
    }
}
