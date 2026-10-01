<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * HTML tags permitted in rich text descriptions, mapped to their allowed attributes.
     *
     * @var array<string, list<string>>
     */
    protected const ALLOWED_DESCRIPTION_TAGS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'a' => ['href', 'target', 'rel'],
    ];

    /**
     * HTML tags removed together with their content.
     *
     * @var list<string>
     */
    protected const DROPPED_DESCRIPTION_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form', 'textarea', 'select',
    ];

    protected $fillable = [
        'merchant_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'image_path',
        'sort_order',
        'is_active',
        'show_in_nav',
        'meta_title',
        'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'show_in_nav' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Determine whether the given category is this category or one of its descendants.
     */
    public function isSelfOrAncestorOf(int $categoryId): bool
    {
        $visited = [];
        $currentId = $categoryId;

        while ($currentId !== null && ! in_array($currentId, $visited, true)) {
            if ($currentId === $this->id) {
                return true;
            }

            $visited[] = $currentId;
            $currentId = self::whereKey($currentId)->value('parent_id');
        }

        return false;
    }

    /**
     * Count the visible text characters of a rich text description.
     */
    public static function descriptionTextLength(?string $html): int
    {
        return mb_strlen(trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected function description(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => self::sanitizeDescription($value));
    }

    /**
     * Strip any markup from a rich text description that is not on the allow list.
     */
    public static function sanitizeDescription(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($document->childNodes as $node) {
            if ($node instanceof DOMElement && $node->nodeName === 'div') {
                $root = $node;
                break;
            }
        }

        if ($root === null) {
            return null;
        }

        self::sanitizeChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        $output = trim($output);

        return $output === '' ? null : $output;
    }

    protected static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if (! $node instanceof DOMElement) {
                $parent->removeChild($node);

                continue;
            }

            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::DROPPED_DESCRIPTION_TAGS, true)) {
                $parent->removeChild($node);

                continue;
            }

            self::sanitizeChildren($node);

            if (! array_key_exists($tag, self::ALLOWED_DESCRIPTION_TAGS)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            $allowedAttributes = self::ALLOWED_DESCRIPTION_TAGS[$tag];
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (! in_array(strtolower($attribute->nodeName), $allowedAttributes, true)) {
                    $node->removeAttribute($attribute->nodeName);
                }
            }

            if ($tag === 'a') {
                $href = trim($node->getAttribute('href'));
                if ($href !== '' && ! preg_match('/^(https?:|mailto:)/i', $href)) {
                    $node->removeAttribute('href');
                }

                if ($node->getAttribute('target') !== '') {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    }
}
