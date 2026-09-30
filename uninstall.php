<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
delete_option('fwerkor_autopic_options');
delete_post_meta_by_key('_fwerkor_autopic_attachment');
delete_post_meta_by_key('_fwerkor_autopic_title_hash');
