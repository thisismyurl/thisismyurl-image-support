# Sister-Plugin Contract

Image Support exposes a stable public surface that companion plugins — like `thisismyurl-heic-support` and `thisismyurl-svg-support` — can call once both are active. This document is the source of truth for that contract.

## Detecting Image Support and checking the version

Before calling anything, verify Image Support is active and at the version you need:

```php
$version = apply_filters( 'timu_image_support_compat_version', '' );

if ( '' === $version || version_compare( $version, '1.0', '<' ) ) {
    // Image Support is not active or is too old — bail.
    return;
}
```

The filter returns the current compat version as a string. If the plugin is inactive the filter returns the default empty string you pass in.

## Compat version: 1.0

These methods are available at `TIMU_IC_Interface::VERSION === '1.0'`.

### TIMU_IC_File_Ops

| Method | Returns | Notes |
|---|---|---|
| `TIMU_IC_File_Ops::process_attachment_for_cleanup( int $attachment_id )` | `true` or `WP_Error` | Runs the full cleanup pipeline: backup, rename, resize, harden, dedupe. |
| `TIMU_IC_File_Ops::restore_image( int $attachment_id )` | `bool` | `true` if the original was restored from backup. |
| `TIMU_IC_File_Ops::should_exclude( string $relative_path )` | `bool` | `true` if the path matches a configured glob pattern. Check before doing anything to a file. |

### TIMU_IC_Audit

| Method | Returns | Notes |
|---|---|---|
| `TIMU_IC_Audit::get_orphan_images()` | `string[]` | Absolute file paths in uploads/ with no attachment DB row. |
| `TIMU_IC_Audit::get_broken_attachments()` | `WP_Post[]` | Attachment posts whose file does not exist on disk. |
| `TIMU_IC_Audit::get_missing_alt_text( array $post_types = [] )` | `int[]` | Attachment IDs missing `_wp_attachment_image_alt`. |
| `TIMU_IC_Audit::find_inline_orphans()` | `array` | Each entry: `['attachment_id' => int, 'appears_in' => int[]]`. |
| `TIMU_IC_Audit::get_exif_data( int $attachment_id )` | `array` | EXIF/IPTC data or empty array on failure. |

## Usage pattern

Sister plugins should guard every call behind the version check shown above. Never call these methods at file-load or in a plugin header — wait for `plugins_loaded` or later:

```php
add_action( 'plugins_loaded', function () {
    $version = apply_filters( 'timu_image_support_compat_version', '' );
    if ( version_compare( $version, '1.0', '<' ) ) {
        return;
    }

    // Safe to call Image Support methods from here.
} );
```

## Stability guarantees

- Methods in this table will not be removed or renamed within the same compat major version.
- New methods may be added in minor bumps; existing signatures will not change.
- A bump to version 2.0 would mean breaking changes. Sister plugins should check `version_compare( $version, '2.0', '<' )` to detect that and bail gracefully.

## File format assumptions

`process_attachment_for_cleanup()` expects the attachment's `_wp_attached_file` meta to be set and the file to exist on disk. It does nothing and returns `WP_Error( 'missing' )` if the file is absent.

If you are registering a new MIME type (as HEIC and SVG plugins do), make sure `TIMU_IC_File_Ops::should_exclude()` returns `false` for your file before you pass it to the cleanup pipeline.

## Questions

Open an issue on [thisismyurl/thisismyurl-image-support](https://github.com/thisismyurl/thisismyurl-image-support) or email cross@thisismyurl.com.
