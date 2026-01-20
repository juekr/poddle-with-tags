<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use Saloon\XmlWrangler\Data\Element;

class Shownotes extends Serializable
{
    public function __construct(public readonly ?string $content)
    {
    }

    public static function fromXmlElement(?Element $element): static
    {
        return new static($element?->getContent());
    }

    public static function fromArray(?array $data): static
    {
        return new static(Arr::get($data ?? [], 'content'));
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
        ];
    }

    public function as_html(): ?string
    {
        return $this->content;
    }

    public function without_tags(): ?string
    {
        return $this->content !== null ? strip_tags($this->content) : null;
    }

    public function as_markdown(): ?string
    {
        if ($this->content === null) {
            return null;
        }

        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $this->content);
        $text = preg_replace('/<\s*\/p\s*>/i', "\n\n", $text);
        $text = preg_replace('/<\s*p[^>]*>/i', '', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5);
        $lines = array_map('trim', preg_split("/\r\n|\r|\n/", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text ?? '');

        return $text;
    }

    public function re_move(string $pattern): static
    {
        if ($this->content === null) {
            return new static(null);
        }

        $updated = preg_replace_callback(
            $pattern,
            static function (array $matches): string {
                if (count($matches) <= 1) {
                    return '';
                }

                $replacement = $matches[0];

                foreach (array_slice($matches, 1) as $match) {
                    if ($match === null || $match === '') {
                        continue;
                    }

                    $replacement = str_replace($match, '', $replacement);
                }

                return $replacement;
            },
            $this->content
        );

        return new static($updated ?? $this->content);
    }
}
