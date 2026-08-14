# Deploying to the WordPress.org plugin directory

How `kloudstack-azure-observability` is published and updated on WordPress.org.

- **Slug:** `kloudstack-azure-observability` (locked — WordPress.org bans "WordPress" in plugin
  slugs, and the slug cannot be changed after approval)
- **SVN repository:** <https://plugins.svn.wordpress.org/kloudstack-azure-observability/>
- **Public page:** <https://wordpress.org/plugins/kloudstack-azure-observability/>
- **Upstream source of truth:** this Git repository, `main` branch

---

## 1. Two channels, one version number

The plugin reaches users two different ways, and they are not the same pipeline:

| | Public | KloudStack managed stacks |
|---|---|---|
| Distributed by | WordPress.org SVN | GitHub release asset |
| Users get updates from | wp-admin → Plugins → Update | The stack image + MU-plugin push |
| Controlled by | `Stable tag` in `readme.txt` | `observability-version.txt` in the `KloudStack ACR Images` repo |

**Both must be updated for a release, and they can drift.** A version published to SVN but not
pinned in `observability-version.txt` means public users are ahead of our own customers. The
reverse means our customers run a build the public cannot get.

Git tags drive the GitHub release; SVN is a separate manual push. Nothing automatically keeps
them in step — that is what the checklist in §7 is for.

---

## 2. One-time setup

### Install Subversion

Not installed on the current workstation. Either:

- **CLI:** `winget install TortoiseSVN.TortoiseSVN` (tick "command line client tools" during
  install), or Chocolatey `choco install svn`
- Verify with `svn --version`

### Credentials

SVN authenticates with your **WordPress.org account username and password** — the same account
that submitted the plugin. There is no separate SVN password and no SSH key.

SVN caches the credential after the first commit. On a shared machine, pass `--no-auth-cache`.

### Check out the repository

```bash
svn co https://plugins.svn.wordpress.org/kloudstack-azure-observability/ wporg-svn
cd wporg-svn
```

Keep this checkout **outside** the Git repository. Two version control systems sharing a working
directory is a reliable way to commit `.svn` into Git or `.git` into SVN.

---

## 3. Repository layout

```
/assets      banners, icons, screenshots      — NOT shipped to users
/trunk       the current development state    — what gets tagged
/tags/2.0.6  an immutable copy of one release — what users download
/branches    unused; we do not branch here
```

Three rules that follow from this:

- **`/assets` is never inside `/trunk`.** Assets are served on the plugin's directory page and
  must not be in the download. Putting them in trunk ships hundreds of KB of PNGs to every user.
- **`/tags/<version>` is written once and never edited.** If a release is wrong, ship a new
  version. Editing a tag changes what users already installed and breaks checksums.
- **`/trunk` should mirror the newest release.** WordPress.org reads plugin headers from trunk
  for some directory metadata even when `Stable tag` points elsewhere.

---

## 4. `Stable tag` — the field that decides everything

In `readme.txt`:

```
Stable tag: 2.0.6
```

This single line determines **which directory users download from**. It is the most dangerous
field in the plugin.

- `Stable tag: 2.0.6` → users get `/tags/2.0.6`
- `Stable tag: trunk` → users get `/trunk` (do not do this; it means every trunk commit ships
  instantly to every site)
- A tag named in `Stable tag` that **does not exist** → the directory falls back to trunk, and a
  half-finished trunk goes out to everyone

**Always commit the tag directory first, then update `Stable tag` last.** That ordering means
there is never a moment where `Stable tag` points at something that is not fully uploaded.

The `readme.txt` inside `/trunk` is the one the directory reads for `Stable tag`. Keep the
version numbers consistent across all four places:

| Location | Field |
|---|---|
| `readme.txt` | `Stable tag: X.Y.Z` |
| `kloudstack-azure-observability.php` | `* Version: X.Y.Z` |
| `kloudstack-azure-observability.php` | `const VERSION = 'X.Y.Z';` |
| Git | tag `vX.Y.Z` |

CI already enforces that the Git tag matches the plugin header (`release.yml`, "Tagged version
must match the plugin header"). It does **not** check SVN.

---

## 5. Assets

Assets live in `/assets` in SVN and are **excluded from the plugin package** — `release.yml`
excludes the `Screenshot/` directory from the ZIP, so nothing here reaches a user's site.

WordPress.org matches assets **by filename**. A file with the wrong name is silently ignored.

| Required filename | Purpose | Status in this repo |
|---|---|---|
| `icon-128x128.png` | directory listing icon | ✅ `Screenshot/icon-128x128.png` |
| `icon-256x256.png` | retina icon | ✅ `Screenshot/icon-256x256.png` |
| `banner-772x250.png` | plugin page header | ✅ `Screenshot/banner-772x250.png` |
| `banner-1544x500.png` | retina header | ✅ `Screenshot/banner-1544x500.png` |
| `screenshot-1.png` | first screenshot | ❌ **missing** |
| `screenshot-2.png` | second screenshot | ❌ **missing** |

> **Action required before first publish.** `readme.txt` declares two screenshots under
> `== Screenshots ==`, and the numbered captions there are matched to `screenshot-1.png` and
> `screenshot-2.png` **by number**. The repository has two suitable images —
> `Screenshot/KloudStack Observability Kloudstack Hosting.png` (the settings screen) and
> `Screenshot/KloudStack Observability Kloudstack Hosting - Testing.png` (the self-test) — but
> under names WordPress.org will not look for. Copy them into `/assets` as `screenshot-1.png`
> and `screenshot-2.png`, in that order, so they match the captions already written.
>
> If they are not renamed, the plugin page shows two captions with no images.

Assets can be committed at any time and take effect within minutes — they are not tied to a
release.

---

## 6. Publishing a release

### 6.1 Build the artifact

Releases are built by CI, not by hand. Tagging is what triggers it:

```bash
git tag v2.0.6
git push origin v2.0.6
```

`release.yml` then builds `kloudstack-azure-observability-<version>.zip`, a `.sha256`
alongside it, and the MU-loader used by stack images.

### 6.2 Use the released ZIP as the source for SVN

**Do not copy files from a Git working tree into SVN.** Unpack the GitHub release asset instead.
The ZIP is what Plugin Check was run against and what the reviewer assessed; copying from a
working tree risks shipping something subtly different — a stray local edit, an untracked file, a
directory the exclude list would have removed.

```bash
# from the SVN checkout
curl -sSLO https://github.com/KloudStack-Hosting/kloudstack-azure-observability/releases/download/v2.0.6/kloudstack-azure-observability-2.0.6.zip
curl -sSLO https://github.com/KloudStack-Hosting/kloudstack-azure-observability/releases/download/v2.0.6/kloudstack-azure-observability-2.0.6.zip.sha256
sha256sum -c kloudstack-azure-observability-2.0.6.zip.sha256   # must print: OK
unzip -q kloudstack-azure-observability-2.0.6.zip -d /tmp/wporg-build
```

The ZIP contains a single top-level directory named after the slug. Its contents — and only its
contents — go into trunk:

```
LICENSE
kloudstack-azure-observability.php
languages/
readme.txt
src/
uninstall.php
```

### 6.3 Replace trunk

```bash
# from the SVN checkout root
rm -rf trunk/*
cp -r /tmp/wporg-build/kloudstack-azure-observability/* trunk/

# SVN does not detect adds and deletes on its own
svn add --force trunk/
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

svn status          # review carefully before committing
```

`svn status` codes: `A` added, `D` deleted, `M` modified, `?` untracked (needs `svn add`),
`!` missing (needs `svn rm`).

### 6.4 Create the tag

Copy **server-side** — it is instant and does not re-upload the files:

```bash
svn cp trunk tags/2.0.6
```

### 6.5 Commit

```bash
svn ci -m "Release 2.0.6"
```

One commit for trunk and the tag together. SVN commits are **immediate and public** — there is no
staging, no amend, and no force-push. Re-read `svn status` before pressing enter.

### 6.6 Verify

- <https://wordpress.org/plugins/kloudstack-azure-observability/> shows the new version
  (allow ~15 minutes for the directory to refresh)
- The "Download" button serves the new version
- wp-admin on a test site offers the update

---

## 7. Release checklist

Copy this into the release PR or issue.

**Before tagging**

- [ ] Version bumped in all four places (§4)
- [ ] `readme.txt` changelog and `== Upgrade Notice ==` entries added
- [ ] `Tested up to:` still accurate for the current WordPress release
- [ ] `.pot` regenerated if any translatable string changed —
      `wp i18n make-pot . languages/kloudstack-azure-observability.pot --slug=kloudstack-azure-observability --domain=kloudstack-azure-observability --exclude=vendor,tests,benchmark,docs,scripts,legacy,loader,node_modules`
      (CI fails the build if this is stale)
- [ ] CI green on `main`

**Before committing to SVN**

- [ ] GitHub release exists with ZIP + `.sha256`
- [ ] `sha256sum -c` passes on the downloaded ZIP
- [ ] Plugin Check run against the **ZIP**, not the repo — no errors at
      `--severity=1 --include-experimental`
- [ ] `svn status` reviewed line by line; no `.git`, `tests/`, `legacy/`, `vendor/`,
      `node_modules/`, `composer.*`
- [ ] `/assets` untouched by this commit unless artwork actually changed

**After committing**

- [ ] Directory page shows the new version
- [ ] `observability-version.txt` in `KloudStack ACR Images` bumped to the same version
- [ ] WordPress image rebuilt and promoted so managed stacks pick it up

---

## 8. Things that will bite

**SVN is not Git.** `svn ci` publishes immediately to a public repository. There is no local
commit, no rebase, no amend, and no way to remove a bad commit — only a new one on top.

**Never edit a tag.** Users who already installed that version, and any checksum taken of it,
both assume it is immutable. Ship a patch version instead.

**`Stable tag` pointing at a missing tag falls back to trunk.** That is how a half-finished trunk
ends up installed on live sites. Commit the tag first; update `Stable tag` last.

**Assets are matched by filename, not by content.** `my-screenshot.png` is ignored. Only
`screenshot-N.png`, `icon-*.png` and `banner-*.png` are recognised.

**The exclude list is case-sensitive.** `release.yml` excludes `Screenshot` with a capital S, and
the directory in Git is `Screenshot/`. They match today. On Windows the filesystem is
case-insensitive so a rename to lowercase would look harmless locally and silently start shipping
the artwork to every user. If that directory is ever renamed, update the exclude in the same
commit.

**Do not commit development files.** `tests/`, `legacy/`, `.github/`, `composer.json`,
`composer.lock`, `vendor/`, `node_modules/`. The release ZIP already excludes all of these — one
more reason to build trunk from the ZIP rather than from a working tree.

**`legacy/` contains a raw `<script>` tag.** It is excluded from the package and must stay
excluded. WordPress.org flags inline script tags, and a reviewer finding one in a shipped file
after approval is a much slower conversation than one before it.

---

## 9. Rolling back

There is no rollback in SVN. To withdraw a bad release:

1. Point `Stable tag` in `trunk/readme.txt` back at the previous good version
2. `svn ci -m "Revert stable tag to 2.0.5 while 2.0.6 is investigated"`
3. Fix forward and release a new version

Users who already updated stay on the bad version until the next release — which is the real
reason for the pre-commit checklist.

---

## 10. First publish (2.0.6)

The initial publish differs from an update only in that `/trunk` and `/tags` start empty and the
assets have never been uploaded.

```bash
svn co https://plugins.svn.wordpress.org/kloudstack-azure-observability/ wporg-svn
cd wporg-svn

# assets — see §5, the two screenshots need renaming first
cp /path/to/icon-128x128.png      assets/
cp /path/to/icon-256x256.png      assets/
cp /path/to/banner-772x250.png    assets/
cp /path/to/banner-1544x500.png   assets/
cp /path/to/screenshot-1.png      assets/
cp /path/to/screenshot-2.png      assets/

# trunk from the verified release ZIP — see §6.2
cp -r /tmp/wporg-build/kloudstack-azure-observability/* trunk/

svn add --force assets/ trunk/
svn cp trunk tags/2.0.6
svn status                       # review
svn ci -m "Initial release 2.0.6"
```

Then work through §6.6 and the "After committing" checklist in §7.
