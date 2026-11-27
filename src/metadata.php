<?php
declare(strict_types=1);

/**
 * Metadata from Tenrai (api.tenrai.org — a free MyAnimeList mirror with a
 * Jikan v4 compatible schema), with MangaDex consulted for content rating,
 * matched exactly through the MyAnimeList id MangaDex links to.
 */

const WK_TENRAI = 'https://api.tenrai.org/v1';
const WK_MANGADEX = 'https://api.mangadex.org';
const WK_USER_AGENT = 'wankarr/' . WK_VERSION . ' (self-hosted manga library)';

class WkHttpError extends RuntimeException {}

/** GET/POST JSON with polite per-host pacing and retry on 429/5xx. */
function wk_http_json(string $url, int $timeout = 20): array
{
    static $last = [];
    $host = (string) parse_url($url, PHP_URL_HOST);
    $gap = str_contains($host, 'tenrai') ? 1.0 : 0.25; // seconds between calls
    for ($attempt = 0; ; $attempt++) {
        $wait = ($last[$host] ?? 0) + $gap - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
        $last[$host] = microtime(true);

        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => WK_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body !== false && $status >= 200 && $status < 300) {
            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                throw new WkHttpError("Invalid JSON from $host");
            }
            return $data;
        }
        if ($status === 404) {
            throw new WkHttpError("Not found: $url", 404);
        }
        $retryable = $body === false || $status === 429 || $status >= 500;
        if (!$retryable || $attempt >= 3) {
            throw new WkHttpError($body === false ? "$host unreachable: $error" : "$host returned HTTP $status", $status);
        }
        $retryAfter = (int) ($headers['retry-after'] ?? 0);
        sleep(min(30, max($retryAfter, 2 ** ($attempt + 1))));
    }
}

/** Save a remote image to disk; returns true when the file exists afterwards. */
function wk_http_download(string $url, string $dest): bool
{
    $tmp = $dest . '.part';
    $fh = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => WK_USER_AGENT,
    ]);
    $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    fclose($fh);
    if ($ok && filesize($tmp) > 0) {
        rename($tmp, $dest);
        return true;
    }
    @unlink($tmp);
    return is_file($dest);
}

// ---------------------------------------------------------------------------
// Tenrai

/** Search Tenrai; returns normalised candidates. */
function wk_tenrai_search(string $query, int $limit = 12): array
{
    $query = trim(mb_substr($query, 0, 100));
    if ($query === '') {
        return [];
    }
    $data = wk_http_json(WK_TENRAI . '/manga?' . http_build_query(['q' => $query, 'limit' => $limit]));
    return array_map('wk_tenrai_candidate', $data['data'] ?? []);
}

function wk_tenrai_get(string $id): array
{
    $data = wk_http_json(WK_TENRAI . '/manga/' . rawurlencode($id) . '/full');
    if (empty($data['data']['mal_id'])) {
        throw new WkHttpError('Unexpected response for manga ' . $id);
    }
    return $data['data'];
}

function wk_tenrai_titles(array $m): array
{
    $titles = [$m['title'] ?? null, $m['title_english'] ?? null, $m['title_japanese'] ?? null];
    foreach ($m['titles'] ?? [] as $t) {
        $titles[] = $t['title'] ?? null;
    }
    foreach ($m['title_synonyms'] ?? [] as $t) {
        $titles[] = $t;
    }
    return array_values(array_unique(array_filter(array_map(fn($t) => is_string($t) ? trim($t) : null, $titles))));
}

function wk_tenrai_candidate(array $m): array
{
    $img = $m['images']['jpg'] ?? [];
    return [
        'provider' => 'tenrai',
        'id' => (string) $m['mal_id'],
        'title' => (string) ($m['title'] ?? ''),
        'title_english' => $m['title_english'] ?? null,
        'titles' => wk_tenrai_titles($m),
        'type' => $m['type'] ?? null,
        'year' => $m['published']['prop']['from']['year'] ?? null,
        'status' => $m['status'] ?? null,
        'volumes' => $m['volumes'] ?? null,
        'score' => $m['score'] ?? null,
        'members' => (int) ($m['members'] ?? 0),
        'cover' => $img['image_url'] ?? null,
        'url' => $m['url'] ?? null,
        'synopsis' => isset($m['synopsis']) ? mb_substr((string) $m['synopsis'], 0, 300) : null,
        'authors' => array_map(fn($a) => wk_person_name((string) $a['name']), $m['authors'] ?? []),
    ];
}

// ---------------------------------------------------------------------------
// Matching

/** How well a candidate fits a series (0–1). */
function wk_match_score(array $series, array $cand): float
{
    $local = array_unique(array_filter([
        $series['title'] ?? null,
        $series['query'] ?? null, // a manual search names the series too
        isset($series['folder']) ? (wk_parse_name((string) $series['folder'], false)['series'] ?? null) : null,
    ]));
    // Main titles count fully. Synonyms count too, but a one-word name that
    // only matches some entry's synonym ("Images", "Love") is too weak to
    // trust automatically.
    $main = array_filter([$cand['title'] ?? null, $cand['title_english'] ?? null]);
    $best = 0.0;
    foreach ($local as $l) {
        $single = !str_contains(wk_norm((string) $l), ' ');
        foreach ($cand['titles'] ?: [$cand['title']] as $t) {
            $score = wk_similarity((string) $l, (string) $t);
            if ($single && !in_array($t, $main, true)) {
                $score -= 0.15;
            }
            $best = max($best, $score);
        }
    }
    // We read images, so prose editions of the same title are a poor fit.
    $type = (string) ($cand['type'] ?? '');
    if (in_array($type, ['Light Novel', 'Novel'], true)) {
        $best -= 0.2;
    } elseif ($type === 'Doujinshi') {
        $best -= 0.05;
    } elseif ($type === 'One-shot' && ($series['book_count'] ?? 1) > 1) {
        $best -= 0.05;
    }
    $localYear = $series['local_year'] ?? $series['year'] ?? null;
    if ($localYear && $cand['year']) {
        $diff = abs((int) $localYear - (int) $cand['year']);
        $best += $diff <= 1 ? 0.03 : ($diff > 3 ? -0.05 : 0);
    }
    return round(max(0.0, min(1.0, $best)), 4);
}

/** Candidates for a series (or a free-text query), best first. */
function wk_match_candidates(array $series, ?string $query = null): array
{
    $query = trim($query ?? $series['title']);
    $cands = wk_tenrai_search($query);
    // Long titles with subtitles often fail exact search; retry the main title.
    if (!$cands && preg_match('/^(.{3,}?)\s*(?::| - | – )/u', $query, $m)) {
        $cands = wk_tenrai_search($m[1]);
    }
    if (wk_norm($query) !== wk_norm((string) $series['title'])) {
        $series['query'] = $query;
    }
    foreach ($cands as &$c) {
        $c['match'] = wk_match_score($series, $c);
    }
    unset($c);
    usort($cands, fn($a, $b) => [$b['match'], $b['members']] <=> [$a['match'], $a['members']]);
    return $cands;
}

/** Try to match a series automatically. Returns the resulting status. */
function wk_auto_match(int $seriesId): string
{
    $series = wk_one(WK_SERIES_SELECT . ' WHERE s.id = ?', [$seriesId]);
    if ($series === null) {
        return 'missing';
    }
    $cands = wk_match_candidates($series);
    $best = $cands[0] ?? null;
    $threshold = (float) wk_config()['metadata']['auto_match'];

    if ($best && $best['match'] >= $threshold) {
        wk_apply_match($seriesId, $best['provider'], $best['id'], $best['match'], 'matched');
        return 'matched';
    }
    wk_update('series', [
        'match_status' => 'unmatched',
        'match_score' => $best['match'] ?? null,
        'match_attempted_at' => time(),
    ], ['id' => $seriesId]);
    wk_log(sprintf('metadata: no confident match for "%s" (best: %s %.2f)', $series['title'], $best['title'] ?? 'none', $best['match'] ?? 0));
    return 'unmatched';
}

/** Fetch full metadata and attach it to a series. */
function wk_apply_match(int $seriesId, string $provider, string $providerId, ?float $score, string $status): void
{
    if ($provider !== 'tenrai') {
        throw new InvalidArgumentException("Unknown provider $provider");
    }
    $full = wk_tenrai_get($providerId);
    $metaId = wk_store_metadata($full);

    $series = wk_one('SELECT title_locked FROM series WHERE id = ?', [$seriesId]);
    $update = [
        'metadata_id' => $metaId,
        'match_status' => $status,
        'match_score' => $score,
        'match_attempted_at' => time(),
        'updated_at' => time(),
    ];
    if ($series && !(int) $series['title_locked']) {
        $title = wk_preferred_title($full);
        $update['title'] = mb_substr($title, 0, 255);
        $update['sort_title'] = mb_substr(wk_sort_title($title), 0, 255);
    }
    wk_update('series', $update, ['id' => $seriesId]);
    wk_series_reindex($seriesId);
}

function wk_preferred_title(array $m): string
{
    $lang = wk_config()['metadata']['title_language'] ?? 'english';
    return (string) (($lang === 'english' ? ($m['title_english'] ?? null) : null) ?: $m['title']);
}

/** Detach metadata from a series and stop automatic matching for it. */
function wk_unmatch(int $seriesId): void
{
    wk_update('series', ['metadata_id' => null, 'match_status' => 'ignored', 'match_score' => null, 'match_attempted_at' => time()], ['id' => $seriesId]);
    wk_series_reindex($seriesId);
}

// ---------------------------------------------------------------------------
// Storage

/** Upsert a Tenrai record with its people and genres; returns metadata id. */
function wk_store_metadata(array $m): int
{
    $malId = (string) $m['mal_id'];
    $genreNames = fn(string $key) => array_map(fn($g) => (string) $g['name'], $m[$key] ?? []);
    $all = array_merge($genreNames('genres'), $genreNames('explicit_genres'), $genreNames('themes'));
    [$rating, $ratingSource] = wk_content_rating($m, $all);
    $date = fn(?array $p) => ($p && $p['year']) ? sprintf('%04d-%02d-%02d', $p['year'], $p['month'] ?: 1, $p['day'] ?: 1) : null;
    $links = array_merge([['name' => 'MyAnimeList', 'url' => $m['url'] ?? null]], $m['external'] ?? []);

    $row = [
        'provider' => 'tenrai',
        'provider_id' => $malId,
        'title' => mb_substr((string) $m['title'], 0, 255),
        'title_english' => isset($m['title_english']) ? mb_substr($m['title_english'], 0, 255) : null,
        'title_japanese' => isset($m['title_japanese']) ? mb_substr($m['title_japanese'], 0, 255) : null,
        'titles' => json_encode(wk_tenrai_titles($m), JSON_UNESCAPED_UNICODE),
        'type' => $m['type'] ?? null,
        'status' => $m['status'] ?? null,
        'synopsis' => $m['synopsis'] ?? null,
        'background' => $m['background'] ?? null,
        'start_date' => $date($m['published']['prop']['from'] ?? null),
        'end_date' => $date($m['published']['prop']['to'] ?? null),
        'year' => $m['published']['prop']['from']['year'] ?? null,
        'volumes' => $m['volumes'] ?? null,
        'chapters' => $m['chapters'] ?? null,
        'score' => $m['score'] ?? null,
        'scored_by' => $m['scored_by'] ?? null,
        'rank_overall' => $m['rank'] ?? null,
        'popularity' => $m['popularity'] ?? null,
        'members' => $m['members'] ?? null,
        'content_rating' => $rating,
        'content_rating_source' => $ratingSource,
        'url' => $m['url'] ?? null,
        'cover_url' => $m['images']['jpg']['large_image_url'] ?? $m['images']['jpg']['image_url'] ?? null,
        'links' => json_encode(array_values(array_filter($links, fn($l) => !empty($l['url']))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'serializations' => json_encode(array_map(fn($s) => $s['name'], $m['serializations'] ?? []), JSON_UNESCAPED_UNICODE),
        'fetched_at' => time(),
    ];

    $id = wk_tx(function () use ($row, $m, $malId) {
        wk_upsert('metadata', $row, ['provider', 'provider_id']);
        $id = (int) wk_val('SELECT id FROM metadata WHERE provider = ? AND provider_id = ?', ['tenrai', $malId]);

        wk_delete('metadata_people', ['metadata_id' => $id]);
        foreach ($m['authors'] ?? [] as $pos => $a) {
            wk_upsert('people', [
                'provider' => 'tenrai', 'provider_id' => (string) $a['mal_id'],
                'name' => mb_substr((string) $a['name'], 0, 255), 'url' => $a['url'] ?? null,
            ], ['provider', 'provider_id']);
            $pid = (int) wk_val('SELECT id FROM people WHERE provider = ? AND provider_id = ?', ['tenrai', (string) $a['mal_id']]);
            wk_upsert('metadata_people', ['metadata_id' => $id, 'person_id' => $pid, 'role' => (string) ($a['role'] ?? 'Author'), 'position' => $pos], ['metadata_id', 'person_id', 'role']);
        }

        wk_delete('metadata_genres', ['metadata_id' => $id]);
        $kinds = ['genres' => 'genre', 'explicit_genres' => 'explicit', 'themes' => 'theme', 'demographics' => 'demographic'];
        foreach ($kinds as $key => $kind) {
            foreach ($m[$key] ?? [] as $g) {
                wk_upsert('genres', ['kind' => $kind, 'name' => mb_substr((string) $g['name'], 0, 100)], ['kind', 'name']);
                $gid = (int) wk_val('SELECT id FROM genres WHERE kind = ? AND name = ?', [$kind, (string) $g['name']]);
                wk_upsert('metadata_genres', ['metadata_id' => $id, 'genre_id' => $gid], ['metadata_id', 'genre_id']);
            }
        }
        return $id;
    });

    if ($row['cover_url']) {
        $path = wk_metadata_cover_path($id);
        $stamp = $path . '.url';
        if (!is_file($path) || @file_get_contents($stamp) !== $row['cover_url']) {
            if (wk_http_download($row['cover_url'], $path)) {
                file_put_contents($stamp, $row['cover_url']);
            }
        }
    }
    return $id;
}

function wk_metadata_cover_path(int $metadataId): string
{
    return wk_data_path("covers/meta/$metadataId.jpg");
}

/**
 * Content rating: MangaDex's explicit rating when we can find the same
 * work there (matched by its MyAnimeList link), else inferred from genres.
 */
function wk_content_rating(array $m, array $genres): array
{
    try {
        foreach (array_slice(array_unique(array_filter([$m['title'] ?? null, $m['title_english'] ?? null])), 0, 2) as $title) {
            $q = http_build_query(['title' => $title, 'limit' => 10]) . '&contentRating[]=safe&contentRating[]=suggestive&contentRating[]=erotica&contentRating[]=pornographic';
            $data = wk_http_json(WK_MANGADEX . '/manga?' . $q, 12);
            foreach ($data['data'] ?? [] as $md) {
                if ((string) ($md['attributes']['links']['mal'] ?? '') === (string) $m['mal_id']) {
                    $r = $md['attributes']['contentRating'] ?? null;
                    if (isset(WK_CONTENT_RATINGS[$r])) {
                        return [$r, 'mangadex'];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        wk_log('metadata: MangaDex rating lookup failed: ' . $e->getMessage());
    }
    $rating = match (true) {
        in_array('Hentai', $genres, true) => 'pornographic',
        in_array('Erotica', $genres, true) => 'erotica',
        in_array('Ecchi', $genres, true) => 'suggestive',
        default => 'safe',
    };
    return [$rating, 'genres'];
}

// ---------------------------------------------------------------------------
// Scheduled refresh

/** Match pending series, retry stale unmatched ones, refresh old metadata. */
function wk_metadata_task(?callable $progress = null): array
{
    $cfg = wk_config()['metadata'];
    $now = time();
    $batch = (int) $cfg['batch'];
    $rows = wk_all(
        "SELECT s.id, s.title, s.match_status, m.provider, m.provider_id, s.match_score
         FROM series s LEFT JOIN metadata m ON m.id = s.metadata_id
         WHERE s.match_status = 'pending'
            OR (s.match_status = 'unmatched' AND s.metadata_id IS NULL AND COALESCE(s.match_attempted_at, 0) < ?)
            OR (s.match_status IN ('matched', 'manual') AND m.id IS NOT NULL AND m.fetched_at < ?)
         ORDER BY CASE s.match_status WHEN 'pending' THEN 0 WHEN 'unmatched' THEN 1 ELSE 2 END, s.id
         LIMIT $batch",
        [$now - 86400 * (int) $cfg['retry_unmatched_days'], $now - 86400 * (int) $cfg['refresh_days']],
    );
    $stats = ['matched' => 0, 'unmatched' => 0, 'refreshed' => 0, 'failed' => 0];
    $failures = 0;
    foreach ($rows as $i => $r) {
        $progress && $progress(sprintf('Metadata %d / %d: %s', $i + 1, count($rows), $r['title']));
        try {
            if ($r['provider'] && in_array($r['match_status'], ['matched', 'manual'], true)) {
                wk_apply_match((int) $r['id'], $r['provider'], $r['provider_id'], $r['match_score'] !== null ? (float) $r['match_score'] : null, $r['match_status']);
                $stats['refreshed']++;
            } else {
                $result = wk_auto_match((int) $r['id']);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            }
            $failures = 0;
        } catch (Throwable $e) {
            $stats['failed']++;
            wk_log("metadata: {$r['title']}: " . $e->getMessage());
            if (++$failures >= 5) {
                throw new RuntimeException('Metadata provider keeps failing, stopping: ' . $e->getMessage());
            }
        }
    }
    if (count($rows) >= $batch) {
        wk_task_make_due('metadata'); // more work queued; continue next run
    }
    return $stats;
}
