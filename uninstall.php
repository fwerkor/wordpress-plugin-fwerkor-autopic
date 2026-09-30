<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
delete_option('fwerkor_autopic_options');
delete_post_meta_by_key('_fwerkor_autopic_attachment_id');
delete_post_meta_by_key('_fwerkor_autopic_signature');
// Generated attachments are intentionally preserved so existing posts keep their featured images.
