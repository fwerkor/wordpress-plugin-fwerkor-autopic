# FWERKOR AutoPic

Generates clean featured-image title cards for WordPress posts that do not have a manual featured image.

## Behavior
- Manual featured images always win.
- Generated images are tracked by post meta.
- A title change regenerates only images created by this plugin.
- Uses local Imagick and a system font; no remote image service.
- Site name is read from WordPress at runtime.
- No site hostname is hard-coded.

Generated attachments are preserved on uninstall so existing posts do not break.
