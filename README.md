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
- Four thumbnail requests per browser; two image conversions per application instance. Requests for folders that were left are cancelled.
- Inputs over 16 million pixels or 40 MB receive a placeholder to prevent excessive GD allocation; the original is still available explicitly.
- Correct single byte-range responses for video/audio and resumable single-file downloads, including byte zero, suffixes, HEAD and invalid-range responses.
- Eight concurrent file transfers, four upload requests, and one archive operation. Excess work receives HTTP 503 and Retry-After instead of starting unlimited expensive requests. Thumbnail and upload requests retry with a finite backoff; a direct download can need to be retried manually if all transfer slots are occupied.
- PHP memory limit 192 MB per request. The supplied Apache server has 32 worker threads and OPcache enabled.
- Uploads use 2 MB chunks, per-upload locks, destination locks, session-bound identities, completion manifests for retries, collision-safe naming and a temporary file that becomes visible only on completion. Disk space under 512 MB stops new chunks/conversions.
- Closing a preview aborts image requests and releases the video/audio source. Hidden browser tabs stop polling drives; visible tabs check every 15 seconds.
- Media responses use private revalidated caching. Source code and server/test configuration are blocked from direct HTTP access by the supplied Apache configuration.

These are conservative initial limits, not a guaranteed number of simultaneous users. Internet upload bandwidth, drive speed, video bitrate and laptop resources determine real capacity. This change does not transcode videos or create adaptive video renditions. Heavy original video can still buffer on a slow connection.

## Maintenance

Startup removes upload temporary directories idle for over 24 hours and cached images older than seven days, checking at most 5,000 entries per category. Run this periodically on a continuously running server:

```powershell
php server/Maintain.php
```

Server configuration and logs are in the ignored `.local-server/` directory. Interrupted uploads are retained until maintenance so retries can resume their chunk state. Completed manifests are also retained briefly to avoid duplicate files on retries.

## Checks

```powershell
php tests/media-test.php
node tests/client-media-test.cjs
```

Integration/load checks were run against an isolated local Apache instance, with synthetic media and test sessions. External network capacity, real SSO login and video codec compatibility require separate validation with representative files and users.
