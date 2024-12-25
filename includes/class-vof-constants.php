<?php
namespace VOF;

defined('ABSPATH') || exit;

class VOF_Constants {
    // Plugin info
    const VERSION = VOF_VERSION;
    const PLUGIN_FILE = VOF_PLUGIN_FILE;
    const PLUGIN_DIR = VOF_PLUGIN_DIR;
    const PLUGIN_URL = VOF_PLUGIN_URL;

    // API
    const REST_NAMESPACE = 'vof/v1';
    
    // Temporary constants (to be moved to settings later)
    const REDIRECT_URL = 'https://thenoise.io';

    // Prevent instantiation
    private function __construct() {}
}