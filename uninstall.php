<?php

// Remove os tokens (criptografados) do banco ao excluir o plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hgcloudflare_settings' );
