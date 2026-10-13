# Drive BMHG

PHP file explorer for Windows data drives, with BMHG SSO. PHP 8.2+ needs GD, cURL, fileinfo, and ZIP. No npm installation or application database is required.

## Run on this laptop

```powershell
powershell -ExecutionPolicy Bypass -File .\server\Start-DriveServer.ps1
```

Open http://127.0.0.1:8085. This starts an isolated Apache configuration using the existing Laragon Apache and PHP 8.3 Thread Safe installation. It does not change Laragon's global configuration. For other installations, pass `-ApacheRoot` and `-PhpRoot`. Ctrl+C stops this foreground server.

Development bypasses SSO only when explicitly enabled and both the remote address and HTTP Host are localhost. C: is excluded by default; attach a data drive or flashdisk to browse files.

For the Cloudflare tunnel or access by other users, use:

```powershell
powershell -ExecutionPolicy Bypass -File .\server\Start-DriveServer.ps1 -Production
```

Point the tunnel to `http://127.0.0.1:8085`. Production keeps SSO enabled, including localhost requests. Authentication should be verified with an allowed BMHG account. Do not use `php -S` for multiple users or streaming media.

## Media and load controls

- Cached WebP/JPEG thumbnails up to 280px; previews up to 1600x1200, with an explicit original-image link.
- Grid and gallery images use browser-native lazy loading and the server's cached 280px thumbnails; previews are reduced to 1600x1200 where supported.
- Large JPEGs (up to 150 million pixels / 80 MB) use FFmpeg's reduced-resolution JPEG decoding when installed, with two simultaneous image jobs and a ten-second timeout. This avoids allocating the full bitmap inside PHP. Other inputs over 16 million pixels / 40 MB receive a lightweight grid placeholder; opening their preview streams the original without PHP decoding. This is also the fallback for unsupported resizing or unavailable cache. Small SVG/ICO images are served directly.
- Correct single byte-range responses for video/audio and resumable single-file downloads, including byte zero, suffixes, HEAD and invalid-range responses.
- Eight concurrent file transfers, four upload requests, and one archive operation. Excess work receives HTTP 503 and Retry-After instead of starting unlimited expensive requests. Thumbnail and upload requests retry with a finite backoff; a direct download can need to be retried manually if all transfer slots are occupied.
- PHP memory limit 192 MB per request. The supplied Apache server has 32 worker threads and OPcache enabled.
- Uploads use 2 MB chunks, per-upload locks, destination locks, session-bound identities, completion manifests for retries, collision-safe naming and a temporary file that becomes visible only on completion. Disk space under 512 MB stops new chunks/conversions.
- Closing a preview aborts image requests and releases the video/audio source. Hidden browser tabs stop polling drives; visible tabs check every 15 seconds.
- Media responses use private revalidated caching. Source code and server/test configuration are blocked from direct HTTP access by the supplied Apache configuration.

## Folder link sharing

Folder owners can create **Pelihat** or **Editor** links. Each recipient signs in through Google SSO on Beasiswa Mahaghora. A guest session can only list, preview and download files inside the shared folder; it cannot use the main Drive API. Editor links also allow uploads, new folders, renaming and deleting items inside that folder. Owners can revoke links from the folder's share dialog. Link records are kept under the private `.local-server/` directory and are not committed to Git.

Configure the BMHG SSO application to allow the callback path `/sso-login` on the Drive domain. The app sends guests directly to the BMHG Google login and returns them to the shared folder after SSO. Only users whose SSO identity is valid can use a link.

These are conservative initial limits, not a guaranteed number of simultaneous users. Internet upload bandwidth, drive speed, video bitrate and laptop resources determine real capacity. Heavy original video can still buffer on a slow connection.

## Cloudflare: low-bandwidth video previews

Run these commands **on the computer serving the Cloudflare tunnel**, from this repository's directory:

```powershell
git pull origin feat/server
powershell -ExecutionPolicy Bypass -File .\server\Install-FFmpeg.ps1
powershell -ExecutionPolicy Bypass -File .\server\Start-DriveServer.ps1 -Production
```

Stop the old server before restarting on the same port. The installer downloads the Windows essentials build linked from the official FFmpeg download page and verifies SHA-256. Binaries stay in the ignored `.local-server/tools/` directory; they are not pushed to GitHub.

If an existing XAMPP/Apache instance already serves the site, keep that server and run the worker separately instead of starting another web server:

```powershell
powershell -ExecutionPolicy Bypass -File .\server\Start-VideoWorker.ps1 -PhpPath C:/xampp/php/php.exe
```

Keep this worker running. The supplied Start-DriveServer launcher starts/stops its worker automatically. The worker prepares one video at a time using two codec threads, caching H.264/AAC MP4 at up to 854x480, 24 fps, video peak bitrate 900 kbit/s and audio 64 kbit/s. Faststart places playback metadata at the beginning. The original upload is not changed.

New video uploads are queued when a worker is active. Existing files are queued when preview is opened. The viewer defaults to **Hemat (480p)** and offers **Video asli** while conversion runs; the original plays temporarily until the cached preview is ready. If the worker is down, the app keeps retrying and shows that the worker must be started on the Cloudflare origin server.

The pending queue is capped at 32 files. Sources above 4 GB, jobs taking over 30 minutes, outputs over 1 GB, or disk space below 512 MB fall back to original playback. Cache and worker status files are blocked from direct HTTP access; requests still authenticate against the original source. This is a single low-bandwidth rendition, not an adaptive HLS system. Actual internet playback still needs sufficient bandwidth for the rendition selected.

## Maintenance

Startup removes upload temporary directories idle for over 24 hours and cached images/videos older than seven days, checking at most 5,000 entries per category. Run this periodically on a continuously running server:

```powershell
php server/Maintain.php
```

Server configuration and logs are in the ignored `.local-server/` directory. Interrupted uploads are retained until maintenance so retries can resume their chunk state. Completed manifests are also retained briefly to avoid duplicate files on retries.

## Checks

```powershell
php tests/media-test.php
node tests/client-media-test.cjs
```

Integration/load checks were run against an isolated local Apache instance, with synthetic media and test sessions. An 18.2-megapixel JPEG and an H.264/AAC video were also tested, including low-bandwidth conversion, byte-range playback and browser playback at 854x480. External network capacity, real SSO login and other video codec compatibility require separate validation with representative files and users.
