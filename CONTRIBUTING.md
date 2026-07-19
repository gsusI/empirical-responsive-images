# Contributing

Thanks for improving Empirical Responsive Images.

Process stays light:

1. Open an issue or pull request with the practical problem you are solving.
2. Keep the change focused.
3. Include before/after notes when behavior changes.
4. Install locked dependencies and run:

```sh
composer install
just check
just release-check
```

If Docker is not available, say what you did run instead.

## Code style

- Follow WordPress coding standards.
- Keep public strings translatable and refresh `languages/empirical-responsive-images.pot`.
- Avoid external services for core image processing.
- Keep front-end rendering free from filesystem writes and expensive image generation.
- Keep release packages free of development notes, local files, and private environment details.

## Pull requests

Small pull requests are easiest to review. A good pull request includes:

- what changed
- why it matters
- how it was tested

No long template required.
