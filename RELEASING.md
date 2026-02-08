# Release Process

The following content can be copied and pasted into a release issue or PR to help provide a checklist of tasks when releasing this project.  When utilizing this template, ensure you do the following.  When pasting this content, ensure to update any `X.Y.Z` nomenclature to the release version being prepared. The person doing the release needs to be [added to the plugin committer list on wp.org](https://wordpress.org/plugins/two-factor/advanced/) (note that this is different from the contributor list), so that they get the release confirmation email, or someone who's already a committer needs to be available to confirm.

```
## Release instructions

- [ ] Branch: Starting from `master`, create a branch named `release/X.Y.Z` for the release-related changes.
- [ ] Version bump: Bump the version number in `readme.txt` and `two-factor.php` if it does not already reflect the version being released.  Update both the plugin "Version:" header value and the plugin `TWO_FACTOR_VERSION` constant in `two-factor.php`.
- [ ] Changelog: Add/update the changelog in `CHANGELOG.md`.  The changelog can be generated from a `compare` URL like [0.8.0...HEAD](https://github.com/WordPress/two-factor/compare/0.8.0...HEAD). Ensure the version number is added to the footer links at the bottom showing the compare from the prior version (e.g., https://github.com/WordPress/two-factor/compare/0.12.0...0.13.0). Trim the changelog entry in `readme.txt` to the least recent between a year about and the prior major release.
- [ ] Props: update `CREDITS.md` file with any new contributors, confirm maintainers are accurate.
- [ ] New files: Check to be sure any new files/paths that are unnecessary in the production version are included in [.distignore](https://github.com/WordPress/two-factor/blob/master/.distignore).
- [ ] Readme updates: Make any other readme changes as necessary. `readme.md` is geared toward GitHub and `readme.txt` contains WordPress.org-specific content. The two are slightly different.
- [ ] Create Release PR: Push any local changes in `release/X.Y.Z` to origin, create a release PR, and request review to ensure all CI checks pass and ensure master branch changes are limited to merges only.
- [ ] Test: Test a ZIP built from the Release PR branch to ensure key plugin functionality continues to work and that the [Tests action](https://github.com/WordPress/two-factor/actions/workflows/test.yml) passes on the PR.
- [ ] Merge: After review approval, merge the release pull request (or make a non-fast-forward merge from your release branch to `master`).  `master` contains the latest stable release.
- [ ] Release: Create a [new release](https://github.com/WordPress/two-factor/releases/new), naming the tag and the release with the new version number, and targeting the `master` branch.  Paste the changelog from `CHANGELOG.md` into the body of the release and include a link to the [closed items on the milestone](https://github.com/WordPress/two-factor/milestone/##?closed=1).  Creating a release will automatically generate & attach zip/tarball files, so you can ignore the GitHub release form asking to uploaded those assets.
- [ ] SVN: Wait for the [GitHub Action: Deploy](https://github.com/WordPress/two-factor/actions/workflows/deploy.yml) to finish deploying to the WordPress.org repository.  
- [ ] Release confirmation: Someone with committer access on WP.org needs to confirm the release at https://wordpress.org/plugins/two-factor/advanced/.  If all goes well, users with SVN commit access for that plugin will receive an emailed diff of changes.
- [ ] Check WordPress.org: Ensure that the changes are live on https://wordpress.org/plugins/two-factor/. This may take a few minutes.
- [ ] Close the milestone: Edit the [milestone](https://github.com/10up/simple-local-avatars/milestone/##) with the release date (in the `Due date (optional)` field) and link to the GitHub release (in the `Description` field), then close the milestone.
- [ ] Punt incomplete items: If any open issues or PRs which were milestoned for `X.Y.Z` do not make it into the release, update their milestone to `X.Y.Z+1`, `X.Y+1.0`, `X+1.0.0` or `Future Release`.`
```