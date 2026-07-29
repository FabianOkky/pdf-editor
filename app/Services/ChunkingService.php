<?php

namespace App\Services;

/**
 * Splits a document's per-page text into overlapping retrieval chunks for RAG. Each chunk stays
 * within a single page so the retriever can cite the exact page a passage came from. Chunks are
 * sized by character count (a cheap, language-agnostic proxy for tokens) and overlap a little so
 * a sentence split across a boundary still lands whole in at least one chunk.
 */
class ChunkingService
{
    /**
     * Chunk the given pages. ``$pages`` is a list of ``{page_number, text}``; the result is a
     * flat list of ``{page_number, chunk_index, content}`` in reading order.
     *
     * @param  list<array{page_number: int, text: string}>  $pages
     * @return list<array{page_number: int, chunk_index: int, content: string}>
     */
    public function chunk(array $pages, int $size, int $overlap): array
    {
        $size = max(1, $size);
        $overlap = max(0, min($overlap, $size - 1));

        $chunks = [];
        $index = 0;

        foreach ($pages as $page) {
            foreach ($this->splitText($page['text'], $size, $overlap) as $piece) {
                $chunks[] = [
                    'page_number' => $page['page_number'],
                    'chunk_index' => $index++,
                    'content' => $piece,
                ];
            }
        }

        return $chunks;
    }

    /**
     * Split one page's text into ~``$size``-char pieces with ``$overlap`` carried between them,
     * preferring to cut on a word boundary so words aren't sliced in half.
     *
     * @return list<string>
     */
    protected function splitText(string $text, int $size, int $overlap): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        $length = mb_strlen($text);

        if ($length <= $size) {
            return [$text];
        }

        $pieces = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $size, $length);

            if ($end < $length) {
                $window = mb_substr($text, $start, $end - $start);
                $cut = mb_strrpos($window, ' ');

                if ($cut !== false && $cut >= (int) ($size / 2)) {
                    $end = $start + $cut;
                }
            }

            $piece = trim(mb_substr($text, $start, $end - $start));

            if ($piece !== '') {
                $pieces[] = $piece;
            }

            if ($end >= $length) {
                break;
            }

            // Step forward by (chunk length − overlap), but always make progress.
            $next = $end - $overlap;
            $start = $next > $start ? $next : $end;
        }

        return $pieces;
    }
}
