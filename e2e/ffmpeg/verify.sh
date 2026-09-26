#!/bin/sh
set -eu

cd /opt/ffmpeg
make -j2 all
make -j2 install

PATH=/opt/ffmpeg-install/bin:$PATH
export PATH
test "$(command -v ffmpeg)" = /opt/ffmpeg-install/bin/ffmpeg
ffmpeg -version | grep -F 'ffmpeg version 8.1.1 '

smoke_dir=$(mktemp -d)
trap 'rm -rf "$smoke_dir"' EXIT

ffmpeg -nostdin -v error \
    -f lavfi -i 'testsrc2=size=64x64:rate=10:duration=1' \
    -f lavfi -i 'sine=frequency=1000:sample_rate=48000:duration=1' \
    -map 0:v -map 1:a -c:v ffv1 -c:a pcm_s16le "$smoke_dir/sample.mkv"

# Compare decoded video pixels to the generated source, ignoring container metadata.
ffmpeg -nostdin -v error -f lavfi -i 'testsrc2=size=64x64:rate=10:duration=1' \
    -map 0:v -c:v rawvideo -f framemd5 "$smoke_dir/expected-video.md5"
ffmpeg -nostdin -v error -i "$smoke_dir/sample.mkv" \
    -map 0:v -c:v rawvideo -f framemd5 "$smoke_dir/actual-video.md5"
grep -v '^#' "$smoke_dir/expected-video.md5" > "$smoke_dir/expected-video"
grep -v '^#' "$smoke_dir/actual-video.md5" > "$smoke_dir/actual-video"
cmp "$smoke_dir/expected-video" "$smoke_dir/actual-video"

# PCM output avoids differences in audio packet boundaries after demuxing.
ffmpeg -nostdin -v error -f lavfi -i 'sine=frequency=1000:sample_rate=48000:duration=1' \
    -c:a pcm_s16le -f s16le "$smoke_dir/expected-audio"
ffmpeg -nostdin -v error -i "$smoke_dir/sample.mkv" \
    -map 0:a -c:a pcm_s16le -f s16le "$smoke_dir/actual-audio"
cmp "$smoke_dir/expected-audio" "$smoke_dir/actual-audio"
test "$(wc -c < "$smoke_dir/actual-audio")" -eq 96000

echo 'FFmpeg build smoke test passed'
