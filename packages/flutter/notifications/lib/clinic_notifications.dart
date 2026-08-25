/// FCM registration, permission, and notification routing.
///
/// **Phase 00 status: declared, not implemented.**
///
/// This package exists now so the workspace layout, the dependency direction,
/// and the ownership boundary are fixed before anyone writes against it. It is
/// implemented in Phase 09.
///
/// Declaring it early is deliberate: the alternative is a later phase inventing
/// a boundary under deadline pressure, which is how a client package ends up
/// reaching into another app's source.
///
/// See `docs/architecture/module-catalog.md` for the ownership rules that
/// apply, and the Phase 09 file for what this package must provide.
library;
