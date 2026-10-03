<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

use RuntimeException;

/**
 * Extracts every documented example from a yq clone's operator and usage doc pages.
 */
final class YqFixtureGenerator
{
    private const string DOC_DIR = '/pkg/yqlib/doc';

    private const array SECTIONS = ['operators', 'usage'];

    public function generate(string $cloneRoot): YqExtraction
    {
        $docRoot = $cloneRoot . self::DOC_DIR;
        if (!is_dir($docRoot)) {
            throw new RuntimeException(\sprintf('Not a yq clone, %s does not exist', $docRoot));
        }

        $extractor  = new YqDocExtractor();
        $extraction = new YqExtraction([], []);

        foreach (self::SECTIONS as $section) {
            $files = glob($docRoot . '/' . $section . '/*.md');
            if (false === $files) {
                throw new RuntimeException('Could not list ' . $docRoot . '/' . $section);
            }

            sort($files);

            foreach ($files as $file) {
                $markdown = file_get_contents($file);
                if (false === $markdown) {
                    throw new RuntimeException('Could not read ' . $file);
                }

                $extraction = $extraction->merge($extractor->extract($markdown, $section . '/' . basename($file)));
            }
        }

        return $extraction;
    }
}
