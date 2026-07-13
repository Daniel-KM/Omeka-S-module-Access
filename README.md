Access (module for Omeka S)
===========================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Access] is a module for [Omeka S] that allows to protect files to be accessed
from the anonymous visitors, but nevertheless available globally or on requests
by guests users, reserved to a list of ips, or available by an email or a token.
Start and end dates of an embargo can be used too, separately or not.

See [below](#usage) for more information on usage.

You may use module [Guest Private] to access public or private resources on
private sites.

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fgitlab.com%2FDaniel-KM%2FOmeka-S-module-Access%2F-%2Fraw%2Fmaster%2Fblueprint.json">
    This module can be tested directly in your browser<br/>
    <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-playground/main/ogimage.png" alt="Try Access in your browser" width="110">
</a><br>


Installation
------------

### Associated modules

To allow access to reserved resources for user with role "Guest", the module
will need to identify users, generally with the module [Guest] or [Guest Role].

To define specific item sets, you can use standard item sets or use the module
[Dynamic Item Sets] to include items automatically in specific items sets
according to metadata.

For old themes, the public part can be managed easily via the module
[Blocks Disposition], but it is recommended to use resource page blocks for new
themes.

The module is compatible with the module [Analytics] (and the older [Statistics])
for download tracking. When Access is active with its own `.htaccess` rule, the
download rule is not needed because Access calls Analytics directly. On uninstall,
the Access rule is automatically converted to an Analytics (or Statistics)
download rule if the module is active (see below).

The module is compatible with the module [Derivative Media] that allows to use
specific derivative files instead original (for example a standard mp4 instead
of a proprietary and unreadable Microsoft wmf). Note the paths should be added
to the Apache config (htaccess).

### Incompatibility

Until version 3.4.16, the module was not compatible with module [Group].

If the module [Statistics] is installed, it must be version 3.4.12 or later. The
version that was tracking downloads was moved to the dedicated module [Analytics].

### Installation of the module

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

* From the zip

Download the last release [Access.zip] from the list of releases, and uncompress
it in the `modules` directory.

* From the source and for development:

If the module was installed from the source, rename the name of the folder of
the module to `Access`.

* For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/Access/phpunit.xml
```

### Configuration of the web server

Without a rewrite rule, the web server (Apache or Nginx) serves files directly,
bypassing Omeka entirely. You **must** configure the web server to redirect file
requests to the module so it can check access rights before serving or denying
the file.

#### Automatic management of .htaccess

The module can automatically manage the Apache rewrite rule in the root
`.htaccess` file. On installation, a rule protecting `original` and `large`
files is written automatically.

The Files to protect sub-section provides:

- Skip `.htaccess` management: when checked, the module will not write or update
  the rewrite rule in the root `.htaccess`. Use this option when you prefer to
  manage the Apache redirections manually (virtual host, manual `.htaccess`
  edits, or an infrastructure where the file is not writable).
- File type checkboxes: select which standard derivative types to protect among
  `original`, `large`, `medium` and `square`.
- Custom types field: add extra path segments separated by spaces, for example
  `mp3 mp4 webm ogg pdf` when using module [Derivative Media].

When you save the configuration, the module inserts or updates a managed block
in the `.htaccess`:

```apache
# Module Access: protect files.
# This rule is automatically managed by the module.
RewriteRule "^files/(original|large)/(.*)$" "access/files/$1/$2" [NC,L]
```

The block is identified by its marker comment, so the module can update or
remove it without affecting the rest of the file.

**When the `.htaccess` is not writable**, the module cannot modify it. In that
case the rule to add or remove is displayed in the configuration form so you can
apply it manually.

**Important**: by default with Omeka, files are **not** protected. Installing
the module alone does not restrict access to files when the `.htaccess` is
write-protected.

##### Legacy rules

If the module detects a rewrite rule that was written manually (without the
marker comment), it is treated as a legacy rule. A warning is displayed with the
detected file types. Saving the configuration converts the legacy rule into the
managed format automatically.

##### Uninstall and module Analytics (ex-part of module Statistics)

When the module is uninstalled, the managed block is removed from the
`.htaccess`. If the module [Analytics] is active and has no download rule of its
own, the Access rule is automatically converted into an Analytics download rule
so that download tracking continues to work:

```apache
# Module Analytics: count downloads.
# This rule is automatically managed by the module.
RewriteRule "^files/(original|large)/(.*)$" "download/files/$1/$2" [NC,L]
```

#### Manual Apache configuration

If you prefer to configure Apache manually, or if the `.htaccess` is not
writable, follow the instructions below.

**WARNING**: Because the configuration of Apache is complex and infrastructures
vary, always check access to free and restricted files from a private browser.

The Apache file `.htaccess` at the root of Omeka should be updated to avoid
direct access to files and to redirect urls to the module.

For that, you have to adapt the following code to your needs in the main [.htaccess]
at the root of Omeka, in a `.htaccess` file in the `files` directory, or
directly in the config of the virtual host of the server (quicker). The example
below is written for the Omeka [.htaccess] file. If you prefer to use other
files, adapt the paths.

In this example, all original and large files will be protected: a check will be
done by the module before delivering files. If the user has no access to a file,
a fake file is displayed.

The small derivatives files (medium and square thumbnails), can be protected too,
but it is generally useless. Anyway, it depends on the original images.

Don't forget to add derivative paths if you use module [Derivative Media].

You can adapt `routes.ini` as you wish too, but this is useless in most cases.

##### Process of the rewrite

The goal of the rewrite rule is to prevent Apache from serving files directly
(bypassing Omeka) and to route the request through the module's controller
(`AccessFileController`). The controller checks the user's rights before serving
the file or a locked placeholder.

The module's Laminas route (`[/access]/files/:type/:filename`) matches both
`/files/...` and `/access/files/...`. A simple internal rewrite `[NC,L]` is
enough: its only role is to prevent Apache from serving the static file directly
(the `RewriteCond %{REQUEST_FILENAME} -f` rule in Omeka's default `.htaccess`
would otherwise bypass PHP entirely). No `mod_proxy` and no external redirect
are needed.

For the flag [P], useless in most of the cases, you should enable the module
Proxy and restart Apache:

```sh
# Depending on your config, either:
sudo a2enmod proxy
# Or something similar:
sudo a2enmod proxy_fcgi
# Then:
sudo systemctl restart apache2
```

##### When Omeka S is installed at the root of a domain or a sub-domain

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`,
eventually adding `|medium|square` to the list of thumbnails:

```apache
# Set rule for original and selected derivative files (usually at least large thumbnails).
RewriteRule "^files/(original|large)/(.*)$" "/access/files/$1/$2" [NC,L]
```

Alternatively, with flag [P] (requires mod_proxy):

```apache
RewriteRule "^files/(original|large)/(.*)$" "/access/files/$1/$2" [P]
```

Using a full URL to force an external redirect (302) is not recommended, as it
adds a round-trip and may expose internal ports behind a reverse proxy:

```apache
# Not recommended.
RewriteRule "^files/(original|large)/(.*)$" "%{REQUEST_SCHEME}://%{HTTP_HOST}/access/files/$1/$2" [NC,L]
```

##### When Omeka S is installed in a sub-path (https://example.org/digital-library/)

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`,
adapting it to your real config (here, the sub-path is `digital-library`),
eventually adding `|medium|square` to the list of thumbnails:

```apache
# Set rule for original and selected derivative files (usually at least large thumbnails).
RewriteRule "^files/(original|large)/(.*)$" "/digital-library/access/files/$1/$2" [NC,L]
```

##### Common issues

- Remove the leading "/" before "files/" in `.htaccess`

  In `.htaccess` context, Apache strips the leading `/` before matching `RewriteRule`
  patterns. Use `^files/...` instead of `^/files/...`. The leading `/` form is
  only valid inside the virtual host configuration.

- Unable to proxy with https (flag [P] only)

  If the flag `[P]` is used with a secured server (https), the certificate
  should be running and up-to-date and the option `SSLProxyEngine on` should
  be set in the Apache config of the web server.

  If you have access to the Apache config, you can include all rules inside it
  directly (`ProxyPass`). If you don't have access to it, just use the full
  unsecure url with `http://` (with real domain or `%{HTTP_HOST}`), for the
  internal proxy. Because it is a fake proxy, it doesn't matter if the
  internal redirect url is unsecure:

```apache
RewriteRule "^files/(original|large)/(.*)$" "http://%{HTTP_HOST}/digital-library/access/files/$1/$2" [P]
```

#### Compatibility with module Analytics (ex-part of module Statistics)

When Access serves files it calls [Analytics] directly, so its own `/access/`
rule is enough and no separate `/download/` rule is needed. **Do not keep a
`/download/` rule alongside the `/access/` rule**: the `/download/` route does
not check access rights, so a private file would become public. On uninstall,
the Access rule is converted back to an Analytics download rule (see
[above](#uninstall-and-module-analytics)).

#### Nginx

The configuration of Apache above should be adapted for Nginx.


Usage
-----

Omeka has two modes of visibility: public or private. The Access module adds a
second, independant check on file content only: the right to download a media
file. The record itself is never hidden by this module: it follows Omeka core
visibility.

So in this module, three independent conditions must be satisfied for a visitor
to download a file:

- the item and the media must be public (Omeka core check);
- the access level of the media must allow access for this visitor (see the
  table below and [Inheritance](#inheritance));
- the embargo, if any, must not block access (see [Embargo](#embargo)).

The access levels and their effect, as summarized in the configuration form:

| Level       | Public item | Media file                                                                                                                            | Admin access request | Author contact |
|-------------|-------------|---------------------------------------------------------------------------------------------------------------------------------------|----------------------|----------------|
| `free`      | Visible     | Downloadable by anyone                                                                                                                | Useless              | Useless        |
| `reserved`  | Visible     | Blocked for anonymous; unlocked by any active bypass (IP, SSO IDP, guest, CAS, LDAP, external, email regex) or by an approved request | Yes                  | Possible       |
| `protected` | Visible     | Blocked for everyone; unlocked only by an approved request. No automatic bypass applies.                                              | Yes (mandatory)      | Possible       |
| `forbidden` | Visible     | Blocked for everyone; no path through the admin access request flow                                                                   | No                   | Sole recourse  |

- Notice visibility: the access level and embargo apply only on files and never
  hide the notice of a resource, which follows Omeka core visibility.
- Independence: visibility (public/private), access level and embargo are
  evaluated separately. Even marked free, a private item or media is never
  accessible on the public side.
- Cascade: a level set on an item set applies to its items and media; a level
  set on an item applies to its media. When several apply, the strictest wins.

The right to view a `reserved` file can be granted through several channels:
the guest role, an external identity provider (module [CAS], [LDAP] or [Single Sign-On]),
by IP (v4 or v6), by email, or by token. For `protected` files, only the
individual access request flow (user/email/token modes) is honoured.

For a "forbidden" resource, the standard admin access request flow is not
offered. The recommended recourse is the view helper `accessContactAuthor($resource)`:
when [Contact Us] module is installed, the helper delegates to its `contactUs`
helper with `contact='author'`, which resolves the author email from a
configured property (`dcterms:creator`) and renders a styled contact form with
consent, hashcash, etc. Without ContactUs, the helper falls back to a short
message inviting the visitor to reach the site administrator (who can then
forward the request to the author).

### Access mode

The rights to see the files are controlled on the fly. The reserved visibility
can be managed in multiple ways:

- Global modes
  - `ip`: all visitors with a specific ip, for example the ip of the physical
    library or the one of a researcher, can have access to all the reserved
    files. Ip can be configured to access specific item sets, for example
    `123.45.67.89 = 51, 54`
  - `guest`: all guest users have access to all the reserved files.
  - `auth_external`: all users authenticated via an external identity provider
    (currently via module CAS and SingleSignOn, later for Ldap) have access
    to media contents.
  - `auth_cas`: all users authenticated via CAS (module [CAS]) have access to all
    reserved files.
  - `auth_ldap`: all users authenticated via Ldap (module [LDAP]) have access to
    all reserved files (currently unsupported).
  - `auth_sso`: all users authenticated via SAML/Shibboleth (module [Single Sign-On])
    have access to all reserved files.
  - `auth_sso_idp`: users authenticated by specified identity providers have
    access to a list of reserved media by item sets.
  - `email_regex`: all authenticated users with an email matching a regex
    pattern have access to all reserved files.
- Individual modes
  - `user`: each file should be made accessible by a specific user one by
    one. So the module has some forms to manage individual requests and accesses.
  - `email`: anybody authenticated via an email have access to specific media
    contents. This protection is simple and light, but not the most secure.
  - `token`: all users or visitor authenticated via a token have access to
    specific media contents.

Individual modes require that an admin allow each right for each resource.

When none of the individual modes (`user`, `email`, `token`) is enabled, the
"Access" tab on the item/media/item-set show page is hidden, and the admin
pages `/admin/access-request` and `/admin/access-log` display an inline
warning stating that these pages only manage individual access requests.

### Identification of the reserved resources

After the configuration, you should identify all resources that you want to make
available via a reserved access. By default, private resources remain private,
so you need to allow visitors to know that they exist. That is to say you can
keep some private resources private, and some other ones available on request,
or globally.

There are two ways to indicate which resources are reserved. The storage mode is
chosen in the sub-section Access rights of the configuration form via the
"Storage of access level and embargo" radio button:

- Resource metadata (default): the access level and embargo are stored as
  specific metadata of the resource, edited via a dedicated radio button in the
  advanced tab of the resource form.
- Property in the resource: the access level and embargo dates are stored as
  standard property values (for example `dcterms:accessRights` or `curation:access`),
  searchable and exportable like any other property.
  The default labels are "free", "reserved", "protected" or "forbidden". The
  properties and the labels can be translated or modified in the config. It
  is recommended to create a custom vocab and to set the datatype as `customvocab:X`
  used via the resource templates to avoid errors in the values.

A public item can have a private media and vice-versa, so the access value may
be set on the media; it can also be set on the item to manage all its media at
once.

### Inheritance

An access level or embargo set on an item set applies automatically to its items
and their media; one set on an item applies to its media. When several apply,
the strictest wins (`free < reserved < protected < forbidden`):

- a restricted item set restricts all its items and media, with no extra step;
- an item or a media can be set stricter than what it inherits, but never looser
  (an item set or an item can only tighten its content, never open it);
- an item that belongs to several item sets takes the strictest of their levels,
  so a cataloguing mistake can only restrict more, never less;
- a media without its own level follows its item: if the item level changes
  later, the media follows.

By default the embargo is per-resource; enable "Cascade embargo dates" to make
it inherit the same way as the level. The level and the embargo are always
checked separately.

The task Rebuild access index (Tasks tab) recomputes all the access levels.
It is normally not needed, only to repair them after a bulk import or a direct
database edit. The task Reset the access status switches an existing base
between two management styles:

- reset item sets to manage access per document (the item sets stop restricting;
  access is set on items and media);
- reset items and media to manage access per item set (access is set on the item
  sets and inherited).

This clears the chosen resource types' levels and is irreversible.

### Advanced search

The advanced search form includes a multi-select filter for access levels. You
can select multiple levels to search for resources matching any of the selected
levels. When no levels are selected, all resources are returned regardless of
their access level.

### Item sets

As indicated above, it is possible to define specific rights by item sets when
using some access mode, mainly ip and sso idp.

In the configuration, the ip and sso idp modes each offer a list of rules. Add
a rule, set its source, then choose the item sets it may reach with the two
item set pickers. For the ip mode, the source is an ip or a cidr range. For
the sso mode, the source is a select of the idps configured in the Single
Sign-On module plus "federation" (a fallback for any federated idp not listed);
a companion field lets you enter a federation idp not in the list, which then
becomes a selectable option once saved.

The item set pickers are:

- Access only to these item sets: the source reaches only resources in the
  selected item sets. Leave empty to grant access to every reserved resource.
- Except these item sets: item sets excluded even when included above, useful
  when a global item set identifies all reserved resources.

Both lists empty means unrestricted access to every reserved resource. The
"Copy item sets" button copies a rule's selection to paste it onto another
rule, so a list shared by many ips or idps is filled once.

The "Edit as a text list" button switches to a single field where all the
rules are edited at once in the legacy `source = ids` format (an id prefixed
with "-" is a forbidden item set), for those who prefer it; switching back, or
saving, applies the text to the rules. The same data is saved either way.

The order matters: the user is checked in the order of the list, so the
federation rule is generally placed last.

The use of the module [Dynamic Item Sets] may be useful, because it allows to
define items included in a item sets according to a standard query.

#### Example for the access mode "ip"

- `124.8.16.32` → allow item sets A and B, except item set C.
- `65.43.21.0/24` → except item set D (access to everything else reserved).

Here, the first ip reaches only resources in item sets A or B but not those in
item set C, while the range reaches all reserved resources except those in
item set D.

#### Reverse proxy and private IPs

When Omeka S is behind a reverse proxy (Traefik, nginx, Apache, Docker bridge,
load balancer), fill the field "Trusted proxies" with the internal IPs of your
reverse proxy. The module then reads `X-Forwarded-For` or `X-Real-IP` instead of
the proxy socket address, but only when the request comes from one of these
trusted IPs, to prevent header spoofing. Without this list, every visitor is
seen with the proxy IP and, if that IP is listed in the rules, all visitors
inherit the rights attached to it.

The trusted proxy is auto-detected on install, on upgrade and on config form
submit: if the list is empty and proxy headers are present on the current admin
request, `REMOTE_ADDR` is added automatically. An existing list is never
overwritten.

The configuration page warns automatically when:
- a reverse proxy header is detected but no trusted proxy is configured;
- trusted proxies are configured but no proxy header is detected on the current
  request;
- a private or loopback IP (RFC1918, 127/8, fc00::/7, link-local, etc.) is
  listed in the access rules, typical sign of a Docker bridge or internal proxy
  leaking to external visitors;
- the IP of the current administrator request is itself listed in the access
  rules and no trusted proxy is configured (critical: indicates a proxy bypass).

#### Authorization endpoint for external services

The module exposes a lightweight endpoint at `/access/authorize` returning the
authorization result as an HTTP status code with an empty body:

- `200`: access allowed
- `403`: access denied
- `404`: media not found

Accepts one of the following query parameters:

- `media=<id>`: internal media id
- `storage=<storage_id>`: storage_id (filename without extension)
- `filename=<storage_id.ext>`: full filename

It reuses the same `isAllowedMediaContent` logic as the main controller (IP
rules, embargo, individual requests, SSO IdP, etc.). The setting "Trusted proxies"
applies, so the endpoint honors `X-Forwarded-For` / `X-Real-IP` only when the
calling service (Cantaloupe, traefik, nginx auth_request) is listed as a trusted
proxy.

Typical uses: Cantaloupe delegate script, Traefik ForwardAuth, nginx `auth_request`,
Apache auth subrequest. Without such a check, an IIIF image server
reverse-proxied on `/iiif/*` fully bypasses Omeka and Access.

A ready-to-use Cantaloupe delegate script is provided at [`data/delegates/cantaloupe.rb`](data/delegates/cantaloupe.rb).
Adjust the `OMEKA_AUTHORIZE_URL` constant and point Cantaloupe at it via `delegate_script.pathname`.
The Cantaloupe server IP must be listed in "Trusted proxies" in the Omeka Access
configuration.

#### Example for the access mode "sso idp"

- `idp.example.org` with both lists empty (access to all reserved resources).
- `shibboleth.another-example.org`: allow item sets A and B, except C.
- `federation`: except item set D.

Here, a user from the first idp reaches all reserved resources; a user from the
second idp reaches only item sets A or B but not C; and any other federated
user reaches all reserved resources except item set D.

### Embargo

By construction, the embargo works only on the media files: metadata are always
visible for public and reserved resources.

The embargo is checked independently from the access level: a file may be free
but under embargo, or reserved without embargo. A per-user bypass is possible
via the setting "embargo bypass". By default the embargo is per-resource; the
setting "embargo cascade" (off by default) makes it inherit item set > item >
media like the level (run the Rebuild access index task after changing it).

To create an embargo on a file, simply set the dates in the advanced tab or use
properties `curation:start` and/or `curation:end`, or the ones specified in the
config.

If you use a property to define the date of the embargo, it is recommended to
use the datatype "numeric timestamp" from the module [Numeric Datatypes], but a
literal is fine. The date must be an iso one (`2022-03-14`). A time can be set
too (`2022-03-14T12:34:56`).

**Important**: Generally, it is enough to set the embargo on the item. Set it on
medias only when there are multiple medias with various status or date of
embargo.

Two settings control what happens when an embargo ends:

- What to do with the access level:
  - `free`: Set access level to "free"
  - `under`: Set access level to the level under ("free" for reserved, "reserved"
    for protected/forbidden)
  - `keep`: Keep the current access level
- What to do with the embargo dates:
  - `clear`: Remove the embargo dates
  - `keep`: Keep the embargo dates

With `keep`, a document stays restricted after its embargo (for example still
university-only); with `free` or `under` it opens, so a facet can show it
"restricted" during the embargo and "free" afterwards.

A job is run automatically once a day to update access status and embargo.

Furthermore, a check is automatically done when an anonymous visitor or a
restricted user is accessing a restricted file. In that case, the media may be
set public automatically when the embargo is finished.

The job does not update the resource when the visibility is not logical, for
example when the resource have been set public with a date of end of embargo.
Of course, don't set a date of end of embargo if the record is not ready or when
it should remain private.

### Status display in admin sidebar

The access status (level and, if any, embargo dates) is displayed in the right
sidebar of the admin pages `item/show`, `item-set/show` and `media/show`, and
also in the resource details sidebar (popup). When a resource has no row in
table `access_status`, the default level `free` is shown, matching the module
runtime behavior (absence of status = free).

### Management of requests

If you choose modes `ip`, `guest`, or `external`, there is nothing to do more.
Once users are authenticated or authorized, they will be able to see the files.

In the case of the single modes `user`, `email` or `token`, there are two ways
to process.

- The admin can made some item sets, items or medias available directly in the
  menu "Access requests" in the sidebar. Simply add a new access, select a
  resource and a user, a email or a token, and the person will be able to view
  it.

- The user or visitor can request an access to a specific resource. It can be
  done directly via a button, for logged users, or via a contact form for the
  anonymous visitors. The contact form may be added by this module or the module
  [Contact Us]. After the request, the admin will receive an email to accept or
  reject the request.

Once accepted, the requester will receive an email with an url to click, that
will add a session cookie that will allow to browse the selected resources.

In public front-end, a dashboard is added for visitors: `/s/my-site/access-request`.
Guest users have a specific board too: `/s/my-site/guest/access-request`.

### Checking stored statuses

In property-storage mode, you can check with an sql query that the stored access
levels match the property values. For example, to list the resources whose value
is "Accès libre" but whose stored level is not `free` (adapt the property id and
the labels to your install):

```sql
SELECT `access_status`.`id`, `access_status`.`level`
FROM `value`
INNER JOIN `access_status` ON `access_status`.`id` = `value`.`resource_id`
WHERE `value`.`property_id` = 185
    AND `value`.`value` = "Accès libre"
    AND `access_status`.`level` != 'free';
```

Run the "Rebuild access index" task to fix any mismatch.


TODO
----

- [x] Fix ip check for ipv6.
- [ ] Use Omeka Store instead of local file system.
- [ ] Update temporal to avoid to check embargo each time via php.
- [x] Reindexation (trigger event?) when embargo is updated automatically. Use the cron task of module EasyAdmin?
- [ ] Clarify process for embargo start and update embargo start date with a specific option (no one seems to use it anyway).
- [ ] Add a mode cas_itemsets to define access rules by cas attributes and item sets (like sso_idp).


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

### Module

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.

### Image Locked file

The image [Locked file] is licensed under [GNU/GPL].


Copyright
---------

* Copyright Daniel Berthereau, 2019-2026 (see [Daniel-KM] on GitLab)
* Copyright Saki (image [Locked file], see [Saki])


[Access]: https://gitlab.com/Daniel-KM/Omeka-S-module-Access
[Omeka S]: https://omeka.org/s
[Guest Private]: https://gitlab.com/Daniel-KM/Omeka-S-module-GuestPrivate
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Guest Role]: https://github.com/biblibre/omeka-s-module-GuestRole
[Dynamic Item Sets]: https://gitlab.com/Daniel-KM/Omeka-S-module-DynamicItemSets
[Blocks Disposition]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlocksDisposition
[Derivative Media]: https://gitlab.com/Daniel-KM/Omeka-S-module-DerivativeMedia
[Group]: https://gitlab.com/Daniel-KM/Omeka-S-module-Group/-/releases
[Contact Us]: https://gitlab.com/Daniel-KM/Omeka-S-module-ContactUs
[CAS]: https://github.com/biblibre/Omeka-S-module-CAS
[LDAP]: https://gitlab.com/Daniel-KM/Omeka-S-module-Ldap
[Single Sign-On]: https://gitlab.com/Daniel-KM/Omeka-S-module-SingleSignOn
[Numeric Datatypes]: https://github.com/omeka-s-modules/NumericDatatypes
[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Analytics]: https://gitlab.com/Daniel-KM/Omeka-S-module-Analytics
[Statistics]: https://gitlab.com/Daniel-KM/Omeka-S-module-Statistics
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Access.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Access/-/releases
[Protect original files]: #protect-original-files
[.htaccess]: https://github.com/omeka/omeka-s/blob/develop/.htaccess.dist#L4
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Access/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Locked file]: http://www.iconarchive.com/show/nuoveXT-icons-by-saki/Mimetypes-file-locked-icon.html
[Saki]: http://www.iconarchive.com/artist/saki.html
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
