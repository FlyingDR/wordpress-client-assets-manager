# wordpress-client-assets-manager
Client assets manager for WordPress.

## Deprecated!

Despite being updated to work properly with actual versions of PHP and WordPress, this library is considered as deprecated.  

The main intention to create this library was to provide a simple way of creating bundles from the CSS / JavaScript assets to optimize the loading speed of the WordPress site pages by decreasing the number of the concurrent HTTP requests.

This task was necessary back at the HTTP/1.1 days, but today's HTTP/2 usability is [close to 100%](https://caniuse.com/http2), so the main feature of this library is not actual anymore.
