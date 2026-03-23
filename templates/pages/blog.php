<?php

/**
 * Blog listing page — now redirects to /home.
 *
 * Blog posts are displayed on the home page (global or family-specific).
 * This redirect keeps old /blog URLs working.
 */

header('Location: /home');
exit;
