Access (module for Omeka S)
===========================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Access] is a module for [Omeka S] that allows to protect files to be accessed
from the anonymous visitors, but nevertheless available globally or on requests
by guests users, reserved to a list of ips, or available by an email or a token.
Start and end dates of an embargo can be used too, separately or not.

Furthermore, you can set the right to see a resource at the media level, so the
item and media metadata are visible and the visitors know that a media exist.
The file itself (the original one and eventually the large and other derivatives
files) is replaced by a fake file, unless a specific right is granted.

See [below](#usage) for more information on usage.


Installation
------------

### Associated modules

To allow access to reserved resources for user with role "Guest", the module
will need to identify users, generally with the module [Guest] or [Guest Role].

The public part can be managed easily via the module [Blocks Disposition], but
it is recommended to use resource page blocks if the theme supports them.

The module is compatible with the module [Statistics]. It is important to
redirect download urls to the module (see below config of ".htaccess").

The module is compatible with the module [Derivative Media] that allows to use
specific derivative files instead original (for example a standard mp4 instead
of a proprietary and unreadable Microsoft wmf). Note the paths should be added
to the Apache config (htaccess).

### Incompatibility

Until version 3.4.16, the module was not compatible with module [Group].

### Installation of the module

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

* From the zip

Download the last release [Access.zip] from the list of releases, and uncompress
it in the `modules` directory.

* From the source and for development:

If the module was installed from the source, rename the name of the folder of
the module to `Access`.

### Configuration of the web server

Omeka does not manage the requests of the files of the web server (generally
Apache or Nginx): they are directly served by it, without any control. To
protect them, you have to tell the web server to redirect the users requests to
Omeka, so it can check the rights, before returning the file or a forbidden
response. You can adapt `routes.ini` as you wish too, but this is useless in
most of the cases.

#### Apache

The Apache file ".htaccess" at the root of Omeka should be updated to avoid
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

If you choose the flag [L], Apache will use the module ModRewrite, that is
already installed for Omeka. For the flag [P], you should enable the module
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
RewriteRule "^/files/(original|large)/(.*)$" "/access/files/$1/$2" [P]
```

An alternative with flag [L]:

```apache
# Set rule for original and selected derivative files (usually at least large thumbnails).
RewriteRule "^/files/(original|large)/(.*)$" "%{REQUEST_SCHEME}://%{HTTP_HOST}/access/files/$1/$2" [L]
```

The request scheme (http or https) is needed when you set the domain, but you can
write it directly without the constant `%{REQUEST_SCHEME}`.

##### When Omeka S is installed in a sub-path (https://example.org/digital-library/)

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`,
adapting it to your real config (here, the sub-path is `digital-library`),
eventually adding `|medium|square` to the list of thumbnails:

```apache
# Set rule for original and selected derivative files (usually at least large thumbnails).
RewriteRule "^/files/(original|large)/(.*)$" "/digital-library/access/files/$1/$2" [P]
```

##### Common issues

First, try with the alternative (flag [P] or [L]).

- Unable to redirect to a virtual proxy with https

  The config uses flag `[P]` for an internal fake `Proxy`, so Apache rewrites
  the path like a proxy. So if there is a redirection to a secured server
  (https), the certificate should be running and up-to-date and the option
  `SSLProxyEngine on` should be set in the Apache config of the web server.

  Anyway, if you have access to it, you can include all rules inside it
  directly (`ProxyPass`). If you don't have access to the Apache config, just
  use the full unsecure url with `http://` (with real domain or `%{HTTP_HOST}`),
  for the internal proxy. Because it is a fake proxy, it doesn't matter if the
  internal redirect url is unsecure:

```apache
# Set rule for original and selected derivative files (usually at least large thumbnails).
RewriteRule "^/files/(original|large)/(.*)$" "http://%{HTTP_HOST}/digital-library/access/files/$1/$2" [P]
```

#### Compatibility with module Statistics

The module is compatible with the module [Statistics].

Because Omeka doesn't protect files by default, **it is important to redirect the urls of the original files**
to the routes of the module Access. If you keep the redirection with `download`,
the check for reserved access won't be done, so **a private file will become public**,
even if a user as a no reserved access to it. For example:

```apache
# Redirect direct access to files to the module Access.
RewriteRule "^/files/(original|large)/(.*)$" "/access/files/$1/$2" [P]

# Redirect direct download of files to the module Access.
RewriteRule "^/download/files/(original|large)/(.*)$" "/access/files/$1/$2" [P]
```

In fact, if not redirected, it acts the same way than a direct access to a
private file in Omeka: they are not protected and everybody who knows the url,
in particular Google, the well-known private life hacker, via Gmail, Chrome,
Android, etc., will have access to it, even if it's exactly what you don't want.

#### Nginx

The configuration of Apache above should be adapted for Nginx.


Usage
-----

Omeka has two modes of visibility: public or private. This module adds a second
check for anonymous or specific users: the right to access to a resource. This
rights has four levels: free, reserved, protected or forbidden. These access
levels applies on record or media files, but the current version supports only
protection of media contents.

So an anonymous visitor can see a public media, but can view the file only if
the level is set to free. The user should have a permission when the level is
reserved or protected, and cannot see it in any case when the level is
forbidden, even if the media is public.

There is no difference between reserved or protected when the type of protection
is limited to files, that is the only type in the current version of the module.

The permission to see a reserved content can be done via many ways: Users can be
checked via the role guest, the authentication via an external identity provider
(module [CAS], [LDAP] and [Single Sign-On]), by ip, by email or by a token.

One important thing to understand is to choose to define the access for each
type of resource: item sets, items and media and to choose if the access is done
recursively during storing or in request. If the request is set to apply
recursively for an item set, all items and medias attached to it will be
available. If the request is set to apply recursively for an item, all medias
attached to it will be available. So when an item set or an item is saved and
when a request is validated, set if the access or the request applies
recursively.

Take care that for resource, the recursivity should be set each time it is
saved, if needed, else access won't be updated to the attached resources. It
allows to have specific access for specific items or medias. Furthermore, this
mechanism does not apply when the access status is set via property. In that
case, all resources are managed individually.

Finally, the option applies only to existing resources: if an item is created
after a change in an item set, it won't apply to it, so you will need to set the
right mode or to update the item set with the recursive option set.

When an embargo is set, it can be bypassed, or not, for the users. Only files
can be under embargo currently.

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
  - `auth_cas`: all users authenticated via CAS (module CAS) have access to all
    reserved files.
  - `auth_ldap`: all users authenticated via Ldap (module Ldap) have access to
    all reserved files (currently unsupported).
  - `auth_sso`: all users authenticated via SAML/Shibboleth (module SingleSignOn)
    have access to all reserved files.
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

### Identification of the reserved resources

After the configuration, you should identify all resources that you want to make
available via a reserved access. By default, private resources remain private,
so you need to allow visitors to know that they exist. That is to say you can
keep some private resources private, and some other ones available on request,
or globally.

There are two ways to indicate which resources are reserved.

- By default, it is a specific setting available as a radio button in the
  advanced tab of the resource form.
- The second way is to set a value to a specified property, for example `curation:access`.
  The value can be "free", "reserved", "protected" or "forbidden". The
  property and the names can be translated or modified in the config. It is
  recommended to create a custom vocab and to use it via the resource templates
  to avoid errors in the values.

A private media remains private. A public media will be accessible only if its
status is not forbidden and not during an embargo, if any.

Note that a public item can have a private media and vice-versa. So, most of the
time, the value should be set in the metadata of the media. The value can be
specified for the item too to simplify management.

### Embargo

By construction, the embargo works only on the media files: metadata are always
visible for public and reserved resources.

An option in the config can be used to use it with or without the reserved
access.

To create an embargo on a file, simply set the dates in the advanced tab or use
properties `curation:start` and/or `curation:end`, or the ones specified in the
config.

If you use a property to define the date of the embargo, it is recommended to
use the datatype "numeric timestamp" from the module [Numeric Datatypes], but a
literal is fine. The date must be an iso one (`2022-03-14`). A time can be set
too (`2022-03-14T12:34:56`).

A check is automatically done when an anonymous visitor or a reserved user is
accessing a file.

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


TODO
----

- [ ] Fix ip check for ipv6.
- [ ] Use Omeka Store instead of local file system.
- [ ] Update temporal to avoid to check embargo each time via php.
- [ ] Reindexation (trigger event?) when embargo is updated automatically.


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

* Copyright Daniel Berthereau, 2019-2025 (see [Daniel-KM] on GitLab)
* Copyright Saki (image [Locked file], see [Saki])


[Access]: https://gitlab.com/Daniel-KM/Omeka-S-module-Access
[Omeka S]: https://omeka.org/s
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Guest Role]: https://github.com/biblibre/omeka-s-module-GuestRole
[Blocks Disposition]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlocksDisposition
[Derivative Media]: https://gitlab.com/Daniel-KM/Omeka-S-module-DerivativeMedia
[Group]: https://gitlab.com/Daniel-KM/Omeka-S-module-Group/-/releases
[Contact Us]: https://gitlab.com/Daniel-KM/Omeka-S-module-ContactUs
[CAS]: https://github.com/biblibre/Omeka-S-module-CAS
[LDAP]: https://gitlab.com/Daniel-KM/Omeka-S-module-Ldap
[Single Sign-On]: https://gitlab.com/Daniel-KM/Omeka-S-module-SingleSignOn
[Numeric Datatypes]: https://github.com/omeka-s-modules/NumericDatatypes
[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
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
