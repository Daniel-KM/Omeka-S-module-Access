Access Resource (module for Omeka S)
====================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Access Resource] is a module for [Omeka S] that allows to protect files to be
accessed from the anonymous visitors, but nevertheless available globally or on
requests by guests users, restricted to a list of ips, or available by a token.
Start and end dates of an embargo can be used too, separately or not.

Furthermore, you can set the right to see a resource at the media level, so the
item and media metadata are visible and the visitors know that a media exist.
The file itself (the original one and eventually the large and other derivatives
files) is replaced by a fake file, unless a specific right is granted.

See [below](#usage) for more information on usage.


Installation
------------

### Associated modules

If the access is reserved by ip or by token, the module can be used standalone.

If the access is reserved globally, the module will need to identify users,
generally with the module [Guest] or [Guest Role]. If the access is restricted
individually, the module [Guest] will be needed to manage the requests. The
public part can be managed easily via the module [Blocks Disposition], or
directly in the theme.

The module is compatible with the module [Statistics]. It is important to
redirect download urls to the module (see below config of ".htaccess").

### Incompatibility

This module is currently incompatible with module [Group], that manages rights
by a list of group.

### Copy of the module

See general end user documentation for [installing a module].

* From the zip

Download the last release [AccessResource.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development:

If the module was installed from the source, rename the name of the folder of
the module to `AccessResource`.

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

The small derivatives files (square and thumbnails), can be protected too, but
it is generally useless. Anyway, it depends on the original images.

##### When Omeka S is installed at the root of a domain or a sub-domain

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`:

```apache
RewriteRule ^files/original/(.*)$ /access/files/original/$1 [P]
RewriteRule ^files/large/(.*)$ /access/files/large/$1 [P]
# Uncomment the lines below to protect square and medium thumbnails too, if really needed.
#RewriteRule ^files/medium/(.*)$ /access/files/medium/$1 [P]
#RewriteRule ^files/square/(.*)$ /access/files/square/$1 [P]
```

##### When Omeka S is installed in a sub-path (https://example.org/digital-library/)

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`,
adapting it to your real config (here, the sub-path is `digital-library`):

```apache
RewriteRule ^files/original/(.*)$ /digital-library/access/files/original/$1 [P]
RewriteRule ^files/large/(.*)$ /digital-library/access/files/large/$1 [P]
# Uncomment the lines below to protect square and medium thumbnails too, if really needed.
#RewriteRule ^files/medium/(.*)$ /digital-library/access/files/medium/$1 [P]
#RewriteRule ^files/square/(.*)$ /digital-library/access/files/square/$1 [P]

RewriteCond %{REQUEST_URI}::$1 ^(/.+)/(.*)::/digital-library/access/\2$
RewriteRule ^(.*) - [E=BASE:%1]
```

##### Common issues

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
RewriteRule ^files/original/(.*)$ http://%{HTTP_HOST}/digital-library/access/files/original/$1 [P]
RewriteRule ^files/large/(.*)$ http://%{HTTP_HOST}/digital-library/access/files/large/$1 [P]
```

#### Compatibility with module Statistics

The module is compatible with the module [Statistics].

Because Omeka doesn't protect files by default, **it is important to redirect the urls of the original files**
to the routes of the module Access Resource. If you keep the redirection with
`download`, the check for restricted access won't be done, so **a private file will become public**,
even if a user as a no restricted access to it. For example:

```apache
# Redirect direct access to files to the module Access Resource.
RewriteRule ^files/original/(.*)$ http://%{HTTP_HOST}/access/files/original/$1 [P]
RewriteRule ^files/large/(.*)$ http://%{HTTP_HOST}/access/files/large/$1 [P]

# Redirect direct download of files to the module Access Resource.
RewriteRule ^download/files/original/(.*)$ http://%{HTTP_HOST}/access/files/original/$1 [P]
RewriteRule ^download/files/large/(.*)$ http://%{HTTP_HOST}/access/files/large/$1 [P]
```

In fact, if not redirected, it acts the same way than a direct access to a
private file in Omeka: they are not protected and everybody who knows the url,
in particular Google via Gmail, Chrome, etc., will have access to it.

#### Nginx

The configuration of Apache above should be adapted for Nginx.


Usage
-----

Omeka has two modes of visibility: public or private. This module adds a third
mode for resource: restricted. It is available globally, by ip, or by user. To
enable this mode of visibility, you should define the access mode and to
identify the restricted resources.

One important thing to understand is to choose to define the access at the item
and/or at the media level. If you choose to set the restricted access on the
item, it will remains private for visitors without rights, even metadata. If you
choose to set the restricted access on the media, the public item metadata will
be viewable by everybody.

When an embargo is set, it can be bypassed, or not, for the users. Only files
can be under embargo currently.

### Access mode

The rights to see the files are controlled on the fly. The restricted visibility
can be managed in three ways, and with or without metadata:

- `global`: all authenticated users have access to all the restricted files. In
  practice, the guest users have no access to private resources, but they can
  view all private resources that are marked restricted.
- `ip`: all visitors with a specific ip, for example the ip of the physical
  library or the one of a researcher, can have access to all the restricted
  files.
- `individual`: each file should be made accessible by a specific user one by
  one. So the module has some forms to manage individual requests and accesses.
  This mode requires the admin to set each right of each resource. This mode can
  be combined with a list of ips to allow visitors with these ips to access to
  any files, as above.

The default mode is `global`. To set the mode `ip` or `individual`, you should
specify it in the file `config/local.config.php` of the Omeka directory:

```php
    'accessresource' => [
        'access_mode' => 'individual',
    ],
```

### Identification of the restricted resources

After the configuration, you should identify all medias that you want to make
available via a restricted access. By default, private resources remain private,
so you need to allow visitors to know that they exist. That is to say you can
keep some private resources private, and some other ones available on request,
or globally.

To indicate which resources are restricted, simply add a value to the property
`curation:reserved`, that is created by the module. When a private resource has
a value for this property, whatever it is (even empty value `0` or `false`), it
becomes available for all guest users, and all visitors can view its metadata
automatically too in listings. Preview will be available for media too. The
value of this property can be private or public.

**Important**: public medias are never restricted, so you need to set them
private.

Note that a public item can have a private media and vice-versa. So, most of the
time, the value should be set in the metadata of the media. The value can be
specified for the item too to simplify management.

### Embargo

By construction, the embargo works only on the media files: metadata are always
visible for public and restricted resources.

An option in the config can be used to use it with or without the restricted
access.

To create an embargo on a file, simply set the dates in `curation:dateStart`
and/or `curation:dateEnd`.

It is recommended to use the datatype "numeric timestamp" from the module [Numeric Datatypes],
but a literal is fine. The date must be an iso one (`2022-03-14`). A time can be
set too (`2022-03-14T12:34:56`).

A check is automatically done when an anonymous visitor or a restricted user is
accessing a restricted file. In that case, the media may be set public
automatically when the embargo is finished. For all other cases, a job may be
run, for example once a day. A cron task can be used through the script designed
to run task of the module [Easy Admin].

The job does not update the resource when the visibility is not logical, for
example when the resource have been set public with a date of end of embargo.
Of course, don't set a date of end of embargo if the record is not ready or when
it should remain private.

### Management of requests

If you choose modes `global` or `ip`, there is nothing to do more. Once users
are authenticated as guest or by ip, they will be able to see the files.

In the case of the individual mode, there are two ways to process.

- The admin can made some medias available directly in the menu "Access Resource"
  in the sidebar. Simply add a new access, select a resource and a user, and he
  will be able to view it. A token is created too: it allows visitors without
  guest account to see the resource.

- The guest user can request an access to a resource they want. It can be done
  directly via a button, for logged users, or via a contact form for the
  anonymous people. The contact form may be added by the module [Contact Us].
  After the request, the admin will receive an email, and he can accept or
  reject the request.

There are forms in a tab for each media and in the left sidebar.

The module allows another access mode, by token. This mode is available only in
the "individual" mode currently. You can find them on access view/edit page.

In public front-end, a dashboard is added for guest users. The link is available
in the guest user board (`/s/my-site/guest/access-resource`).


TODO
----

- [ ] Make metadata or resource hidden, not only files (so a more restricted type of access).
- [ ] Make resources available by token in global mode.
- [ ] Make resources available by token only, not login (like module Contribute).
- [x] Make non-exclusive mode "ip" and "individual".
- [ ] Fix ip check for ipv6.
- [ ] Use Omeka Store instead of local file system.
- [x] Manage ip by item set or by user instead of sites?
- [ ] Store openess in a specific table for performance and to manage embargo easier.
- [ ] Manage the case where the embargo dates are private.
- [ ] Add a mode to check for a specific value in the reserved access property instead of exist/not exist.


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

* Copyright Daniel Berthereau, 2019-2022 (see [Daniel-KM] on GitLab)
* Copyright Saki (image [Locked file], see [Saki])


[Access Resource]: https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource
[Omeka S]: https://omeka.org/s
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Guest Role]: https://github.com/biblibre/omeka-s-module-GuestRole
[Blocks Disposition]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlocksDisposition
[Group]: https://gitlab.com/Daniel-KM/Omeka-S-module-Group/-/releases
[Contact Us]: https://gitlab.com/Daniel-KM/Omeka-S-module-ContactUs
[Numeric Datatypes]: https://github.com/omeka-s-modules/NumericDatatypes
[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Statistics]: https://gitlab.com/Daniel-KM/Omeka-S-module-Statistics
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[AccessResource.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource/-/releases
[Protect original files]: #protect-original-files
[.htaccess]: https://github.com/omeka/omeka-s/blob/develop/.htaccess.dist#L4
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Locked file]: http://www.iconarchive.com/show/nuoveXT-icons-by-saki/Mimetypes-file-locked-icon.html
[Saki]: http://www.iconarchive.com/artist/saki.html
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
