# Changelog: zerofront/module-zero-product-feed

## 1.3.6

- Control characters that XML forbids (pasted into a description, say) are removed, so one product no longer makes the whole feed unreadable.
- A feed that fails is logged and the others are still written, by the cron and by the command; a failed feed leaves no temporary file behind.

Earlier releases (1.3.5 and before): see the Git tags.
