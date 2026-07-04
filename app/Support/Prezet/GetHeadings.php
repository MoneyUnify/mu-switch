<?php

namespace App\Support\Prezet;

use League\CommonMark\Normalizer\SlugNormalizer;
use Prezet\Prezet\Actions\GetHeadings as BaseGetHeadings;

/**
 * "On this page" heading extraction whose ids match the in-body anchor ids.
 *
 * Prezet's default {@see BaseGetHeadings} slugifies heading text with a
 * different algorithm than CommonMark's HeadingPermalink extension (which
 * renders the actual `id="content-…"` anchors in the body). For headings with
 * punctuation (e.g. "Built-in driver") the two diverge — the base produces
 * `content-builtin-driver` while the body anchor is `content-built-in-driver` —
 * so the table-of-contents links resolve to nothing and never scroll.
 *
 * This override recomputes each heading id from its title using the SAME
 * normalizer CommonMark uses, keeping the two perfectly in sync.
 */
class GetHeadings extends BaseGetHeadings
{
    /**
     * The id prefix configured for the HeadingPermalink extension
     * (config/prezet.php → commonmark.config.heading_permalink.id_prefix).
     */
    private const ID_PREFIX = 'content';

    public function __construct(private readonly SlugNormalizer $slugNormalizer = new SlugNormalizer) {}

    /**
     * @return array<int, array<string, array<int, array<string, string>>|string>>
     */
    public function handle(string $html): array
    {
        return array_map(fn (array $heading): array => $this->realign($heading), parent::handle($html));
    }

    /**
     * Recompute a heading's id (and its children's) from its title.
     *
     * @param  array<string, mixed>  $heading
     * @return array<string, mixed>
     */
    private function realign(array $heading): array
    {
        $heading['id'] = $this->headingId((string) ($heading['title'] ?? ''));

        if (! empty($heading['children']) && is_array($heading['children'])) {
            $heading['children'] = array_map(fn (array $child): array => $this->realign($child), $heading['children']);
        }

        return $heading;
    }

    private function headingId(string $title): string
    {
        return self::ID_PREFIX.'-'.$this->slugNormalizer->normalize($title);
    }
}
