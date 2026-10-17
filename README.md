# downloadRenameBot

2gb+ Telegram file/YouTube upload&amp;renamer bot based on [MadelineProto &raquo;](https://docs.madelineproto.xyz).  

Download+reuploads are done on the fly, **no disk space is used**.

## Installation

```
wget https://github.com/danog/downloadRenameBot/raw/main/bot.php && php bot.php
```

Don't forget to install the [required dependencies](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html) for MadelineProto; **yt-dlp** and **ffmpeg** are only needed to upload the videos of websites other than YouTube.

## YouTube videos

YouTube videos are uploaded in up to 1080p (the best H.264 MP4 that fits in 2 GB), entirely in pure PHP (see the `DownloadRenameBot` namespace in `bot.php`): like yt-dlp, their formats are got with the visionOS InnerTube client and a visitor ID, which need no JavaScript player nor PO token; their H.264 video and AAC audio are fetched a range at a time, and muxed into a fragmented MP4 file on the fly, without ffmpeg and without storing anything on disk.


