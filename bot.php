<?php declare(strict_types=1);
/**
 * Example bot.
 *
 * Copyright 2016-2020 Daniil Gentili
 * (https://daniil.it)
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace DownloadRenameBot {
    use Amp\ByteStream\ReadableStream;
    use Amp\ByteStream\ReadableStreamIteratorAggregate;
    use Amp\Cancellation;
    use Amp\CancelledException;
    use Amp\DeferredFuture;
    use Amp\ForbidCloning;
    use Amp\Http\Client\HttpClient;
    use Amp\Http\Client\HttpClientBuilder;
    use Amp\Http\Client\Request;
    use Closure;
    use danog\MadelineProto\API;
    use Generator;
    use IteratorAggregate;
    use RuntimeException;
    use Throwable;

    use function Amp\delay;

    // MadelineProto is already loaded
    if (class_exists(API::class)) {
        // Otherwise, if a stable version of MadelineProto was installed via composer, load composer autoloader
    } elseif (file_exists('vendor/autoload.php')) {
        require_once 'vendor/autoload.php';
    } else {
        // Otherwise download an !!! alpha !!! version of MadelineProto via madeline.php
        if (!file_exists('madeline.php')) {
            copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
        }
        require_once 'madeline.php';
    }

    /**
     * A YouTube video could not be uploaded.
     */
    final class YouTubeException extends RuntimeException
    {
    }

    /**
     * Gets the formats of YouTube videos, from the InnerTube API, like yt-dlp does.
     *
     * @internal
     */
    final class YouTube
    {
        private const PLAYER_URL = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
        private const WATCH_URL = 'https://www.youtube.com/watch?bpctr=9999999999&has_verified=1&v=';
        private const WEB_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
        /**
         * The InnerTube clients to try, in order, from yt-dlp: their formats have plain URLs, without
         * a signature (or an n parameter) to decipher with the JavaScript player of YouTube.
         *
         * The formats of the VISIONOS client can be fetched whole without a PO token, those of the
         * ANDROID client often can't be fetched past their first megabyte.
         */
        private const CLIENTS = [
            [
                'id' => 101,
                'context' => [
                    'clientName' => 'VISIONOS',
                    'clientVersion' => '1.02',
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'RealityDevice17,1',
                    'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_7_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
                    'osName' => 'visionOS',
                    'osVersion' => '26.5.23O471',
                ],
            ],
            [
                'id' => 3,
                'context' => [
                    'clientName' => 'ANDROID',
                    'clientVersion' => '21.26.364',
                    'androidSdkVersion' => 30,
                    'userAgent' => 'com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip',
                    'osName' => 'Android',
                    'osVersion' => '11',
                ],
            ],
        ];
        /** How long to use the same visitor ID for, in seconds. */
        private const VISITOR_DATA_TTL = 6 * 3600;
        /** YouTube video links: https://www.youtube.com/watch?v=<id>, https://youtu.be/<id>, https://www.youtube.com/shorts/<id>, and so on. */
        private const LINK = '~^(?:https?://)?(?:(?:www|m|music)\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:\S*?&)?v=|shorts/|live/|embed/|v/)|youtu\.be/)([\w-]{11})(?![\w-])~i';

        private static ?HttpClient $client = null;
        /** @var array{string, int}|null The visitor ID, and when it was got. */
        private static ?array $visitorData = null;

        public static function getClient(): HttpClient
        {
            return self::$client ??= HttpClientBuilder::buildDefault();
        }

        /**
         * The number of clients, to try them all.
         */
        public static function getClientCount(): int
        {
            return \count(self::CLIENTS);
        }

        /**
         * The ID of the video a YouTube link points to, or null if it isn't one.
         */
        public static function parseLink(string $link): ?string
        {
            return preg_match(self::LINK, $link, $matches) ? $matches[1] : null;
        }

        /**
         * Get a visitor ID from the watch page of a video: without one, player requests are refused
         * ("Sign in to confirm you're not a bot").
         */
        private static function getVisitorData(string $videoId, ?Cancellation $cancellation): string
        {
            if (self::$visitorData !== null && time() - self::$visitorData[1] < self::VISITOR_DATA_TTL) {
                return self::$visitorData[0];
            }
            $request = new Request(self::WATCH_URL.$videoId);
            $request->setHeader('User-Agent', self::WEB_USER_AGENT);
            $request->setHeader('Accept-Language', 'en-US,en');
            $page = self::getClient()->request($request, $cancellation)->getBody()->buffer($cancellation);
            if (!preg_match('~"VISITOR_DATA":"([^"]+)"~', $page, $matches)) {
                throw new YouTubeException('Could not get a visitor ID from YouTube');
            }
            self::$visitorData = [$matches[1], time()];
            return $matches[1];
        }

        /**
         * Get the player response of a video.
         *
         * @param int $firstClient The client to try first: the next ones are tried if it fails.
         *
         * @return array{array, string, int} The player response, the user agent to fetch its formats
         *                                   with, and the client it was got with.
         */
        public static function getPlayer(string $videoId, ?Cancellation $cancellation = null, int $firstClient = 0): array
        {
            $reason = 'unknown error';
            $count = self::getClientCount();
            for ($i = 0; $i < $count; $i++) {
                $index = ($firstClient + $i) % $count;
                $client = self::CLIENTS[$index];
                try {
                    $request = new Request(self::PLAYER_URL, 'POST', json_encode([
                        'context' => ['client' => $client['context'] + ['hl' => 'en', 'gl' => 'US']],
                        'videoId' => $videoId,
                        'contentCheckOk' => true,
                        'racyCheckOk' => true,
                    ], JSON_THROW_ON_ERROR));
                    $request->setHeader('Content-Type', 'application/json');
                    $request->setHeader('User-Agent', $client['context']['userAgent']);
                    $request->setHeader('Origin', 'https://www.youtube.com');
                    $request->setHeader('X-YouTube-Client-Name', (string) $client['id']);
                    $request->setHeader('X-YouTube-Client-Version', $client['context']['clientVersion']);
                    $request->setHeader('X-Goog-Visitor-Id', self::getVisitorData($videoId, $cancellation));
                    $response = self::getClient()->request($request, $cancellation);
                    $player = json_decode($response->getBody()->buffer($cancellation), true);
                } catch (CancelledException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $reason = $e->getMessage();
                    continue;
                }
                if (!\is_array($player)) {
                    $reason = "HTTP error {$response->getStatus()}";
                    continue;
                }
                $status = $player['playabilityStatus'] ?? [];
                if (($status['status'] ?? null) === 'OK' && isset($player['streamingData'])) {
                    return [$player, $client['context']['userAgent'], $index];
                }
                if (($status['status'] ?? null) === 'LOGIN_REQUIRED') {
                    // The visitor ID may have been flagged: get a new one next time.
                    self::$visitorData = null;
                }
                $reason = $status['reason'] ?? $status['status'] ?? $reason;
            }
            throw new YouTubeException($reason);
        }

        /**
         * The best MP4 H.264 video format (YouTube has them in up to 1080p) no bigger than the given size, if any.
         */
        public static function pickVideo(array $player, int $maxSize): ?array
        {
            $formats = array_filter(
                self::getFormats($player),
                static fn (array $format): bool => str_starts_with($format['mimeType'], 'video/mp4; codecs="avc1.')
                    && $format['size'] <= $maxSize,
            );
            usort($formats, static fn (array $a, array $b): int => [$b['height'] ?? 0, $b['fps'] ?? 0, $b['bitrate'] ?? 0]
                <=> [$a['height'] ?? 0, $a['fps'] ?? 0, $a['bitrate'] ?? 0]);
            return $formats[0] ?? null;
        }

        /**
         * The best MP4 AAC audio format, in the original language, if any.
         */
        public static function pickAudio(array $player): ?array
        {
            $formats = array_filter(
                self::getFormats($player),
                static fn (array $format): bool => $format['mimeType'] === 'audio/mp4; codecs="mp4a.40.2"'
                    && !($format['isDrc'] ?? false),
            );
            usort($formats, static fn (array $a, array $b): int => [$b['audioTrack']['audioIsDefault'] ?? true, $b['bitrate'] ?? 0]
                <=> [$a['audioTrack']['audioIsDefault'] ?? true, $a['bitrate'] ?? 0]);
            return $formats[0] ?? null;
        }

        /**
         * Find a format again, by its itag and size.
         */
        public static function findFormat(array $player, int $itag, int $size): ?array
        {
            foreach (self::getFormats($player) as $format) {
                if ($format['itag'] === $itag && $format['size'] === $size) {
                    return $format;
                }
            }
            return null;
        }

        /**
         * The formats that can be fetched, along with their size.
         *
         * @return list<array>
         */
        private static function getFormats(array $player): array
        {
            $formats = [];
            foreach ($player['streamingData']['adaptiveFormats'] ?? [] as $format) {
                if (isset($format['url'], $format['contentLength'], $format['itag'], $format['mimeType'])) {
                    $format['size'] = (int) $format['contentLength'];
                    $formats[] = $format;
                }
            }
            return $formats;
        }
    }

    /**
     * Reading and writing MP4 boxes.
     *
     * @internal
     */
    final class Mp4
    {
        /**
         * Read the header of a box.
         *
         * @param int $available How many bytes there are from the offset to the end of the file or parent box.
         *
         * @return array{string, int, int} The type, header length and size of the box.
         */
        public static function header(string $data, int $offset, int $available): array
        {
            if (\strlen($data) < $offset + 8) {
                throw new YouTubeException('Truncated MP4 box');
            }
            $size = unpack('N', $data, $offset)[1];
            $type = substr($data, $offset + 4, 4);
            $headerLength = 8;
            if ($size === 1) {
                if (\strlen($data) < $offset + 16) {
                    throw new YouTubeException('Truncated MP4 box');
                }
                $size = unpack('J', $data, $offset + 8)[1];
                $headerLength = 16;
            } elseif ($size === 0) {
                // The box extends to the end of the file.
                $size = $available;
            }
            if ($size < $headerLength || $size > $available) {
                throw new YouTubeException("Invalid size of MP4 box $type");
            }
            return [$type, $headerLength, $size];
        }

        /**
         * Iterate over the child boxes of a box.
         *
         * @return Generator<int, array{string, string}> The type and payload of each box.
         */
        public static function children(string $data): Generator
        {
            $offset = 0;
            $end = \strlen($data);
            while ($offset < $end) {
                [$type, $headerLength, $size] = self::header($data, $offset, $end - $offset);
                yield [$type, substr($data, $offset + $headerLength, $size - $headerLength)];
                $offset += $size;
            }
        }

        /**
         * Write a box.
         */
        public static function box(string $type, string $payload): string
        {
            return pack('N', 8 + \strlen($payload)).$type.$payload;
        }

        /**
         * Overwrite a 32-bit integer.
         */
        public static function setUint32(string $data, int $offset, int $value): string
        {
            return substr_replace($data, pack('N', $value), $offset, 4);
        }
    }

    /**
     * Reads a file a range at a time.
     *
     * @internal
     */
    final class RangeReader
    {
        /** How much is fetched at a time, in bytes. */
        private const CHUNK_SIZE = 4 * 1024 * 1024;

        private string $buffer = '';
        private int $bufferOffset = 0;

        /**
         * @param Closure(int, int): string $fetch Fetches the bytes of the file between two offsets, inclusive.
         */
        public function __construct(
            private readonly Closure $fetch,
            public readonly int $size,
        ) {
        }

        /**
         * Read bytes of the file, fetching them (and a chunk ahead) if needed.
         */
        public function read(int $offset, int $length): string
        {
            if ($offset + $length > $this->size) {
                throw new YouTubeException('The file is truncated');
            }
            $bufferEnd = $this->bufferOffset + \strlen($this->buffer);
            if ($offset < $this->bufferOffset || $offset > $bufferEnd) {
                // Skipping ahead: drop the buffer.
                $this->buffer = '';
                $this->bufferOffset = $bufferEnd = $offset;
            }
            if ($offset + $length > $bufferEnd) {
                // Drop what was read already, then fetch what's missing.
                $this->buffer = substr($this->buffer, $offset - $this->bufferOffset);
                $this->bufferOffset = $offset;
                $end = min($this->size, max($offset + $length, $bufferEnd + self::CHUNK_SIZE));
                $this->buffer .= ($this->fetch)($bufferEnd, $end - 1);
            }
            return substr($this->buffer, $offset - $this->bufferOffset, $length);
        }

        /**
         * Read the header of the box at an offset.
         *
         * @return array{string, int, int} The type, header length and size of the box.
         */
        public function readHeader(int $offset): array
        {
            $available = $this->size - $offset;
            return Mp4::header($this->read($offset, min(16, $available)), 0, $available);
        }
    }

    /**
     * Reads the fragments of a fragmented MP4 file with a single track, as YouTube serves them.
     *
     * @internal
     */
    final class Mp4Track
    {
        /** The payload of the mvhd box. */
        public readonly string $mvhd;
        /** The payload of the trak box. */
        public readonly string $trak;
        /** The payload of the trex box. */
        public readonly string $trex;
        /** The payload of the mehd box, if any. */
        public readonly ?string $mehd;
        /** The media timescale of the track. */
        public readonly int $timescale;

        /**
         * The next fragment, if already read.
         *
         * @var array{time: int, moof: string, mdat: string, sequence: int, tracks: list<int>}|null
         */
        private ?array $next = null;
        private int $offset = 0;

        public function __construct(private readonly RangeReader $reader)
        {
            // The moov box precedes the first fragment.
            while (true) {
                if ($this->offset >= $reader->size) {
                    throw new YouTubeException('The MP4 file has no fragments');
                }
                [$type, $headerLength, $size] = $reader->readHeader($this->offset);
                if ($type === 'moof') {
                    break;
                }
                if ($type === 'moov') {
                    $this->parseMoov($reader->read($this->offset + $headerLength, $size - $headerLength));
                }
                $this->offset += $size;
            }
            if (!isset($this->trak)) {
                throw new YouTubeException('The MP4 file has no moov box');
            }
        }

        private function parseMoov(string $moov): void
        {
            $mehd = null;
            foreach (Mp4::children($moov) as [$type, $payload]) {
                if ($type === 'mvhd') {
                    $this->mvhd = $payload;
                } elseif ($type === 'trak') {
                    if (isset($this->trak)) {
                        throw new YouTubeException('The MP4 file has more than one track');
                    }
                    $this->trak = $payload;
                } elseif ($type === 'mvex') {
                    foreach (Mp4::children($payload) as [$childType, $child]) {
                        if ($childType === 'trex') {
                            $this->trex = $child;
                        } elseif ($childType === 'mehd') {
                            $mehd = $child;
                        }
                    }
                }
            }
            $this->mehd = $mehd;
            if (!isset($this->mvhd, $this->trak, $this->trex)) {
                throw new YouTubeException('The MP4 file is not fragmented');
            }
            $mdhd = self::find($this->trak, 'mdia', 'mdhd') ?? throw new YouTubeException('The MP4 track has no mdhd box');
            $this->timescale = unpack('N', $mdhd, \ord($mdhd[0]) === 1 ? 20 : 12)[1];
        }

        /**
         * Find a box by its path.
         */
        public static function find(string $data, string ...$path): ?string
        {
            $type = array_shift($path);
            foreach (Mp4::children($data) as [$childType, $payload]) {
                if ($childType === $type) {
                    return $path === [] ? $payload : self::find($payload, ...$path);
                }
            }
            return null;
        }

        /**
         * Read the next fragment, without consuming it.
         *
         * @return array{time: int, moof: string, mdat: string, sequence: int, tracks: list<int>}|null The
         *         decode time of the fragment, its moof and mdat boxes, and the offsets of its sequence
         *         number and of its track IDs in the moof box; null at the end of the file.
         */
        public function peek(): ?array
        {
            if ($this->next !== null) {
                return $this->next;
            }
            $moof = null;
            while ($this->offset < $this->reader->size) {
                [$type, , $size] = $this->reader->readHeader($this->offset);
                $box = $this->reader->read($this->offset, $size);
                $this->offset += $size;
                if ($type === 'moof') {
                    $moof = $box;
                } elseif ($type === 'mdat' && $moof !== null) {
                    return $this->next = self::parseMoof($moof) + ['moof' => $moof, 'mdat' => $box];
                }
                // Anything else (sidx, styp, free...) is dropped.
            }
            return null;
        }

        /**
         * Consume the fragment returned by {@see self::peek()}.
         */
        public function consume(): void
        {
            $this->next = null;
        }

        /**
         * @return array{time: int, sequence: int, tracks: list<int>}
         */
        private static function parseMoof(string $moof): array
        {
            $sequence = null;
            $tracks = [];
            $time = null;
            [, $headerLength] = Mp4::header($moof, 0, \strlen($moof));
            foreach (self::boxes($moof, $headerLength, \strlen($moof)) as [$type, $start, $end]) {
                if ($type === 'mfhd') {
                    // After the version and flags.
                    $sequence = $start + 4;
                } elseif ($type === 'traf') {
                    foreach (self::boxes($moof, $start, $end) as [$childType, $childStart]) {
                        if ($childType === 'tfhd') {
                            if (unpack('N', $moof, $childStart)[1] & 0x000001) {
                                // A base data offset would point into the original file.
                                throw new YouTubeException('Unsupported MP4 fragment with a base data offset');
                            }
                            $tracks[] = $childStart + 4;
                        } elseif ($childType === 'tfdt') {
                            $time ??= \ord($moof[$childStart]) === 1
                                ? unpack('J', $moof, $childStart + 4)[1]
                                : unpack('N', $moof, $childStart + 4)[1];
                        }
                    }
                }
            }
            if ($sequence === null || $tracks === [] || $time === null) {
                throw new YouTubeException('Invalid MP4 fragment');
            }
            return ['time' => $time, 'sequence' => $sequence, 'tracks' => $tracks];
        }

        /**
         * Iterate over the boxes between two offsets.
         *
         * @return Generator<int, array{string, int, int}> The type of each box, and where its payload starts and ends.
         */
        private static function boxes(string $data, int $offset, int $end): Generator
        {
            for (; $offset < $end; $offset += $size) {
                [$type, $headerLength, $size] = Mp4::header($data, $offset, $end - $offset);
                yield [$type, $offset + $headerLength, $offset + $size];
            }
        }
    }

    /**
     * A YouTube video, as a fragmented MP4 file with H.264 video and AAC audio, that Telegram can play as it's downloaded.
     *
     * YouTube serves the video and the audio of a video as separate fragmented MP4 files: they are
     * fetched a range at a time, and muxed into a single file on the fly, without ffmpeg (and without
     * storing anything on disk).
     *
     * @implements IteratorAggregate<int, string>
     */
    final class YouTubeStream implements ReadableStream, IteratorAggregate
    {
        use ReadableStreamIteratorAggregate;
        use ForbidCloning;

        private const VIDEO_TRACK = 1;
        private const AUDIO_TRACK = 2;
        /** How many times to try fetching a range. */
        private const ATTEMPTS = 5;

        /** @var array{video?: Mp4Track, audio?: Mp4Track} */
        private array $tracks = [];
        /**
         * The URLs of the formats (which expire), and the user agent to fetch them with.
         *
         * @var array{video: string, audio: string, userAgent: string}|null
         */
        private ?array $urls;
        private ?Cancellation $cancellation = null;
        /** The sequence number of the next fragment. */
        private int $sequence = 1;
        private bool $headerRead = false;
        private bool $eof = false;
        private bool $closed = false;
        private DeferredFuture $onClose;

        /**
         * @param array{itag: int, size: int} $video The video format.
         * @param array{itag: int, size: int} $audio The audio format.
         */
        private function __construct(
            public readonly string $videoId,
            public readonly string $title,
            /** The duration, in seconds. */
            public readonly int $duration,
            public readonly int $width,
            public readonly int $height,
            /** The URL of a JPEG thumbnail, 320x180. */
            public readonly string $thumbnail,
            private readonly array $video,
            private readonly array $audio,
            /** The InnerTube client to get the URLs with, see {@see YouTube::getPlayer()}. */
            private int $client,
            array $urls,
        ) {
            $this->urls = $urls;
            $this->onClose = new DeferredFuture;
        }

        /**
         * Open a YouTube video.
         *
         * @param string $videoId The ID of the video, see {@see YouTube::parseLink()}.
         * @param int    $maxSize The maximum size of the file.
         *
         * @throws YouTubeException If it can't be uploaded.
         */
        public static function open(string $videoId, int $maxSize, ?Cancellation $cancellation = null): self
        {
            [$player, $userAgent, $client] = YouTube::getPlayer($videoId, $cancellation);
            if ($player['videoDetails']['isLive'] ?? false) {
                throw new YouTubeException("Live streams can't be uploaded");
            }
            $audio = YouTube::pickAudio($player)
                ?? throw new YouTubeException('This video has no AAC audio');
            // The boxes rewritten by the muxer take a few kilobytes at most.
            $video = YouTube::pickVideo($player, $maxSize - $audio['size'] - 1024 * 1024)
                ?? throw new YouTubeException('This video has no H.264 video (small enough to be uploaded)');
            return new self(
                $videoId,
                $player['videoDetails']['title'] ?? $videoId,
                (int) ($player['videoDetails']['lengthSeconds'] ?? 0),
                $video['width'] ?? 0,
                $video['height'] ?? 0,
                "https://i.ytimg.com/vi/$videoId/mqdefault.jpg",
                ['itag' => $video['itag'], 'size' => $video['size']],
                ['itag' => $audio['itag'], 'size' => $audio['size']],
                $client,
                ['video' => $video['url'], 'audio' => $audio['url'], 'userAgent' => $userAgent],
            );
        }

        #[\Override]
        public function read(?Cancellation $cancellation = null): ?string
        {
            if ($this->closed || $this->eof) {
                return null;
            }
            $this->cancellation = $cancellation;
            try {
                if (!$this->headerRead) {
                    $header = $this->readHeader();
                    $this->headerRead = true;
                    return $header;
                }
                $fragment = $this->readFragment();
            } finally {
                $this->cancellation = null;
            }
            if ($fragment === null) {
                $this->eof = true;
                $this->tracks = [];
            }
            return $fragment;
        }

        /**
         * Read the ftyp and moov boxes of the file, with both tracks.
         */
        private function readHeader(): string
        {
            $video = $this->getTrack('video');
            $audio = $this->getTrack('audio');
            $videoTimescale = self::getMovieTimescale($video->mvhd);
            $audioTimescale = self::getMovieTimescale($audio->mvhd);
            // The next track ID is the last field of mvhd.
            $mvhd = Mp4::setUint32($video->mvhd, \strlen($video->mvhd) - 4, self::AUDIO_TRACK + 1);
            $mvex = ($video->mehd === null ? '' : Mp4::box('mehd', $video->mehd))
                .Mp4::box('trex', Mp4::setUint32($video->trex, 4, self::VIDEO_TRACK))
                .Mp4::box('trex', Mp4::setUint32($audio->trex, 4, self::AUDIO_TRACK));
            return Mp4::box('ftyp', 'isom'.pack('N', 0x200).'isomiso6avc1mp41')
                .Mp4::box(
                    'moov',
                    Mp4::box('mvhd', $mvhd)
                    .Mp4::box('trak', self::rewriteTrak($video->trak, self::VIDEO_TRACK, $videoTimescale, $videoTimescale))
                    .Mp4::box('trak', self::rewriteTrak($audio->trak, self::AUDIO_TRACK, $audioTimescale, $videoTimescale))
                    .Mp4::box('mvex', $mvex),
                );
        }

        private static function getMovieTimescale(string $mvhd): int
        {
            return unpack('N', $mvhd, \ord($mvhd[0]) === 1 ? 20 : 12)[1];
        }

        /**
         * Set the ID of a track, and convert its durations (in movie timescale) from one movie to another.
         */
        private static function rewriteTrak(string $trak, int $id, int $from, int $to): string
        {
            $scale = static fn (int $duration): int => intdiv($duration * $to, $from);
            $result = '';
            foreach (Mp4::children($trak) as [$type, $payload]) {
                if ($type === 'tkhd') {
                    if (\ord($payload[0]) === 1) {
                        $payload = Mp4::setUint32($payload, 20, $id);
                        $payload = substr_replace($payload, pack('J', $scale(unpack('J', $payload, 28)[1])), 28, 8);
                    } else {
                        $payload = Mp4::setUint32($payload, 12, $id);
                        $payload = Mp4::setUint32($payload, 20, $scale(unpack('N', $payload, 20)[1]));
                    }
                } elseif ($type === 'edts') {
                    $edts = '';
                    foreach (Mp4::children($payload) as [$childType, $child]) {
                        if ($childType === 'elst') {
                            $version = \ord($child[0]);
                            $entrySize = $version === 1 ? 20 : 12;
                            $count = unpack('N', $child, 4)[1];
                            for ($i = 0, $offset = 8; $i < $count; $i++, $offset += $entrySize) {
                                $child = $version === 1
                                    ? substr_replace($child, pack('J', $scale(unpack('J', $child, $offset)[1])), $offset, 8)
                                    : Mp4::setUint32($child, $offset, $scale(unpack('N', $child, $offset)[1]));
                            }
                        }
                        $edts .= Mp4::box($childType, $child);
                    }
                    $payload = $edts;
                }
                $result .= Mp4::box($type, $payload);
            }
            return $result;
        }

        /**
         * Read the next fragment, of the track that's behind.
         */
        private function readFragment(): ?string
        {
            $video = $this->getTrack('video');
            $audio = $this->getTrack('audio');
            $videoFragment = $video->peek();
            $audioFragment = $audio->peek();
            if ($videoFragment === null && $audioFragment === null) {
                return null;
            }
            $isVideo = $videoFragment !== null && ($audioFragment === null
                || $videoFragment['time'] * $audio->timescale <= $audioFragment['time'] * $video->timescale);
            $fragment = $isVideo ? $videoFragment : $audioFragment;
            ($isVideo ? $video : $audio)->consume();
            $moof = Mp4::setUint32($fragment['moof'], $fragment['sequence'], $this->sequence++);
            foreach ($fragment['tracks'] as $offset) {
                $moof = Mp4::setUint32($moof, $offset, $isVideo ? self::VIDEO_TRACK : self::AUDIO_TRACK);
            }
            return $moof.$fragment['mdat'];
        }

        /**
         * @param 'video'|'audio' $kind
         */
        private function getTrack(string $kind): Mp4Track
        {
            return $this->tracks[$kind] ??= new Mp4Track(new RangeReader(
                fn (int $start, int $end): string => $this->fetch($kind, $start, $end),
                $this->{$kind}['size'],
            ));
        }

        /**
         * Fetch a range of the video or audio file, getting new URLs if needed.
         *
         * @param 'video'|'audio' $kind
         */
        private function fetch(string $kind, int $start, int $end): string
        {
            $errors = [];
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->urls ??= $this->getUrls();
                    $request = new Request($this->urls[$kind]);
                    $request->setHeader('Range', "bytes=$start-$end");
                    $request->setHeader('User-Agent', $this->urls['userAgent']);
                    $request->setTransferTimeout(120);
                    $request->setInactivityTimeout(30);
                    $response = YouTube::getClient()->request($request, $this->cancellation);
                    $body = $response->getBody()->buffer($this->cancellation);
                    $status = $response->getStatus();
                    if ($status === 206 && \strlen($body) === $end - $start + 1) {
                        return $body;
                    }
                    if ($status === 403 || $status === 410) {
                        // The URLs expired, were requested from another IP address (they're bound to
                        // it), or are refused (sometimes for no reason): get new ones, from the next
                        // client if they're refused again.
                        $this->urls = null;
                        if ($attempt % 2 === 0) {
                            $this->client++;
                        }
                    }
                    throw new YouTubeException("HTTP status $status, got ".\strlen($body).' bytes');
                } catch (CancelledException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    if ($attempt >= self::ATTEMPTS) {
                        throw new YouTubeException("Could not fetch the $kind of $this->videoId: ".implode('; ', array_unique($errors)), 0, $e);
                    }
                    delay($attempt, cancellation: $this->cancellation);
                }
            }
        }

        /**
         * Get new URLs for the formats, from the first client (starting from the current one) that has them.
         *
         * @return array{video: string, audio: string, userAgent: string}
         */
        private function getUrls(): array
        {
            $missing = null;
            for ($i = 0; $i < YouTube::getClientCount(); $i++) {
                [$player, $userAgent, $client] = YouTube::getPlayer($this->videoId, $this->cancellation, $this->client + $i);
                $urls = ['userAgent' => $userAgent];
                foreach (['video' => $this->video, 'audio' => $this->audio] as $kind => $format) {
                    $found = YouTube::findFormat($player, $format['itag'], $format['size']);
                    if ($found === null) {
                        $missing = $kind;
                        continue 2;
                    }
                    $urls[$kind] = $found['url'];
                }
                $this->client = $client;
                return $urls;
            }
            throw new YouTubeException("The $missing of $this->videoId isn't available anymore");
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isReadable(): bool
        {
            return !$this->closed && !$this->eof;
        }

        #[\Override]
        public function close(): void
        {
            $this->closed = true;
            $this->tracks = [];
            if (!$this->onClose->isComplete()) {
                $this->onClose->complete();
            }
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isClosed(): bool
        {
            return !$this->isReadable();
        }

        #[\Override]
        public function onClose(Closure $onClose): void
        {
            $this->onClose->getFuture()->finally($onClose);
        }
    }
}

namespace {
    use Amp\ByteStream\ReadableStream;
    use Amp\Process\Process;
    use danog\MadelineProto\EventHandler\Attributes\Handler;
    use danog\MadelineProto\EventHandler\CommandType;
    use danog\MadelineProto\EventHandler\Filter\Combinator\FilterNot;
    use danog\MadelineProto\EventHandler\Filter\FilterCommand;
    use danog\MadelineProto\EventHandler\Media;
    use danog\MadelineProto\EventHandler\Message\PrivateMessage;
    use danog\MadelineProto\EventHandler\SimpleFilter\HasMedia;
    use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
    use danog\MadelineProto\EventHandler\SimpleFilter\IsNotEdited;
    use danog\MadelineProto\Logger;
    use danog\MadelineProto\ParseMode;
    use danog\MadelineProto\RemoteUrl;
    use danog\MadelineProto\RPCErrorException;
    use danog\MadelineProto\Settings;
    use danog\MadelineProto\SimpleEventHandler;
    use DownloadRenameBot\YouTube;
    use DownloadRenameBot\YouTubeException;
    use DownloadRenameBot\YouTubeStream;
    use League\Uri\Contracts\UriException;
    use League\Uri\Uri;

    use function Amp\async;
    use function Amp\ByteStream\buffer;
    use function Amp\ByteStream\getStderr;
    use function Amp\ByteStream\pipe;

    if (PHP_SAPI !== 'cli') {
        echo("Please run this bot via CLI in a screen session or docker image: https://docs.madelineproto.xyz/docs/DOCKER.html!".PHP_EOL);
        die(1);
    }

    /**
     * Whether a command can be run.
     *
     * @param list<string> $command
     */
    function canRun(array $command): bool
    {
        try {
            $process = Process::start($command);
        } catch (Throwable) {
            return false;
        }
        async(buffer(...), $process->getStdout())->ignore();
        async(buffer(...), $process->getStderr())->ignore();
        return $process->join() === 0;
    }

    // YouTube videos are uploaded in pure PHP: yt-dlp and ffmpeg are only needed for the videos of other websites.
    define('YT_DLP', canRun(['yt-dlp', '--version']) && canRun(['ffmpeg', '-h']));
    if (!YT_DLP) {
        echo("yt-dlp or ffmpeg is not installed: only YouTube videos and file URLs can be uploaded, install yt-dlp and ffmpeg to upload the videos of other websites!".PHP_EOL);
    }

    /**
     * Event handler class.
     *
     * All properties returned by __sleep are automatically stored in the database.
     */
    class MyEventHandler extends SimpleEventHandler
    {
        public const START = "Send me a file URL and I will download it and send it to you!\n\n".
                    "Usage: `/upload https://example.com`\n".
                    "Usage: `/upload https://example.com file name.ext`\n\n".
                    "YouTube videos are uploaded in up to 1080p.\n\n".
                    "I can also rename Telegram files, just send me any file and I will rename it!\n\n".
                    "Max 2.0GB, parallel upload and download powered by @MadelineProto.";

        /** The maximum size of a file, in bytes. */
        private const MAX_SIZE = 2000 * 1024 * 1024;

        /**
         * @var int|string Username or ID of bot admin
         */
        public const ADMIN = 'danogentili'; // Change this

        /**
         * Get peer(s) where to report errors.
         *
         * @return int|string|array
         */
        public function getReportPeers()
        {
            return [self::ADMIN];
        }

        /**
         * Returns a list of names for properties that will be automatically saved to the session database (MySQL/postgres/redis if configured, the session file otherwise).
         */
        public function __sleep(): array
        {
            return ['states'];
        }

        /**
         * Array of media objects.
         */
        private array $states = [];

        /**
         * Start.
         */
        #[FilterCommand('start', [CommandType::SLASH])]
        public function cmdStart(PrivateMessage&Incoming&IsNotEdited $message): void
        {
            $message->reply(self::START, ParseMode::MARKDOWN);
        }

        /**
         * Save user file.
         */
        #[Handler]
        public function cmdSaveState(PrivateMessage&Incoming&HasMedia&IsNotEdited $message): void
        {
            $message->reply('Give me a new name for this file: ', ParseMode::MARKDOWN);
            $this->states[$message->chatId] = $message->media;
        }

        /**
         * Upload a url or a youtube video.
         */
        #[FilterCommand('upload', [CommandType::SLASH])]
        public function cmdYt(PrivateMessage&Incoming&IsNotEdited $message): void
        {
            $url  = $message->commandArgs[0] ?? null;
            $name = $message->commandArgs[1] ?? null;
            if ($url === null) {
                $message->reply(self::START, ParseMode::MARKDOWN);
                return;
            }

            if ($videoId = YouTube::parseLink($url)) {
                try {
                    $video = YouTubeStream::open($videoId, self::MAX_SIZE);
                } catch (YouTubeException $e) {
                    $message->reply("I can't upload this YouTube video: {$e->getMessage()}");
                    return;
                }
                async(
                    $this->cmdUpload(...),
                    $video,
                    $name ?? "{$video->title}.mp4",
                    $message,
                    $video->duration,
                    $video->width,
                    $video->height,
                    // MadelineProto needs gd for thumbnails (without one, it generates one with ffmpeg, if installed).
                    extension_loaded('gd') ? new RemoteUrl($video->thumbnail) : null,
                )->finally($video->close(...));
                return;
            }

            $info = null;
            if (YT_DLP) {
                $process = Process::start([
                    'yt-dlp',
                    $url,
                    '-J',
                ]);
                $info = json_decode(
                    buffer($process->getStdout()),
                    true,
                );
                $process->join();
            }
            if (isset($info['title'])) {
                $name ??= $info['title'].".mp4";
                $url = escapeshellarg($url);
                $process = Process::start([
                    'bash',
                    '-c',
                    "yt-dlp $url -f bestvideo*+bestaudio/best -o - | ffmpeg -i - -f mp4 -acodec copy -vcodec copy -movflags isml+frag_keyframe pipe:1",
                ]);
                async(pipe(...), $process->getStderr(), getStderr())->ignore();

                $finally = $process->join(...);
                $url = $process->getStdout();
            } else {
                $name ??= $url;
                if (Uri::new($url)->getScheme() === null) {
                    $url = "http://$url";
                }
                $url = new RemoteUrl($url);
                $finally = static fn () => null;
            }

            async(
                $this->cmdUpload(...),
                $url,
                $name,
                $message
            )->finally($finally);
        }

        /**
         * Change file name.
         */
        #[FilterNot(new FilterCommand('upload', [CommandType::SLASH]))]
        public function cmdNameFile(PrivateMessage&Incoming&IsNotEdited $message): void
        {
            if (isset($this->states[$message->chatId])) {
                $name = $message->message;
                $url  = $this->states[$message->chatId];
                unset($this->states[$message->chatId]);
                $this->cmdUpload($url, $name, $message);
            }
        }

        private function cmdUpload(
            Media|RemoteUrl|ReadableStream $file,
            string $name,
            PrivateMessage $message,
            ?int $duration = null,
            ?int $width = null,
            ?int $height = null,
            ?RemoteUrl $thumb = null,
        ): void {
            try {
                $sent = $message->reply('Preparing...');
                $prev = 0;
                $this->sendVideo(
                    peer        : $message->chatId,
                    replyToMsgId: $message->id,
                    fileName: $name,
                    file: $file,
                    thumb: $thumb,
                    duration: $duration,
                    width: $width,
                    height: $height,
                    caption: 'Powered by @MadelineProto!',
                    callback: static function ($progress, $speed, $time) use ($sent, &$prev): void {
                        $now = time();
                        if ($now - $prev < 10 && $progress < 100) {
                            return;
                        }

                        $prev = $now;
                        try {
                            $sent->editText("Upload progress: $progress%\nSpeed: $speed mbps\nTime elapsed since start: $time");
                        } catch (RPCErrorException $e) {
                        }
                    },
                );
                $sent->delete();
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Could not connect to URI')   && !($e instanceof UriException) && !str_contains($e->getMessage(), 'URI') && !($e instanceof YouTubeException)) {
                    $this->report((string) $e);
                    $this->logger((string) $e, Logger::FATAL_ERROR);
                }
                try {
                    $sent->editText('Error: ' . $e->getMessage());
                } catch (Throwable $e) {
                    $this->logger((string) $e, Logger::FATAL_ERROR);
                }
            }
        }
    }

    $settings = new Settings;
    $settings->getConnection()->setMaxMediaSocketCount(1000);
    // In this case we don't need full caching
    $settings->getPeer()->setFullFetch(false)->setCacheAllPeersOnStartup(false);
    // Reduce boilerplate with new wrapper method.
    // Also initializes error reporting, catching and reporting all errors surfacing from the event loop.
    MyEventHandler::startAndLoop('bot.madeline', $settings);
}
