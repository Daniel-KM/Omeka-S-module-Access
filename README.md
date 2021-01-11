Access Resource (module for Omeka S)
====================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Access Resource] is a module for [Omeka S] that allows to protect files to be
accessed from the anonymous visitors, but nevertheless available for some guest
users, on identification or by token.


Installation
------------

### Copy of the module

The module depends on module [Guest], so install it first. The button in the
public part can be managed easily via the module [Blocks Disposition], or
directly in the theme.

See general end user documentation for [installing a module].

* From the zip

Download the last release [AccessResource.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development:

If the module was installed from the source, rename the name of the folder of
the module to `AccessResource`.

### Configuration of the web server (Apache or Nginx)

The Apache file ".htaccess" at the root of Omeka should be updated to avoid
direct access to files and to redirect requests to the module. See below [Protect original files].


Usage
-----

Omeka has two modes of visibility: public or private. This module has a third
mode for the medias, restricted. When enabled, the metadata of the restricted
private medias are viewable, but not the original files (and eventually the
large and other derivatives).

### Access mode

Furthermore, the restricted visibility can be managed in two ways:
- global: all authenticated users have access to all the restricted files. In
  practice, the guest users have no access to private resources, but they can
  view all private resources that are marked restricted.
- individual: each file should be made accessible by a specific user one by one.
  So the module has some forms to manage individual requests and accesses. This
  mode requires the admin to set each rights.

The default mode is "global". To set the mode "individual", you should specify
it in the file `config/local.config.php` of the Omeka directory:

```php
    'accessresource' => [
        'access_mode' => 'individual',
    ],
```

### Access and protect original files

Omeka does not manage the requests of the files of the web server (generally
Apache or Nginx): they are directly served by it, without any control. To
protect them, you have to tell the web server to redirect the users requests to
Omeka, so it can check the rights, before returning the file or a forbidden
response. For that, you have to adapt the following code to your needs in the
main [.htaccess] at the root of Omeka, in a `.htaccess` file in the `files`
directory, or directly in the config of the virtual host of the server (quicker).
The example below is written for the Omeka [.htaccess] file. If you prefer to
use other files, adapt the paths.

In this example, all original and large files will be protected: a check will be
done by the module before delivering files. If the user has no access to a file,
a fake file is displayed.

The small derivatives files (square and thumbnails), can be protected too, but
it is generally useless. Anyway, it depends on the original images.

You can adapt `routes.ini` as you wish too.

#### When Omeka S is installed at the root of a domain

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`:

```Apache
RewriteRule ^files/original/(.*)$ /access/files/original/$1 [P]
RewriteRule ^files/large/(.*)$ /access/files/large/$1 [P]
# Uncomment the lines below to protect square and medium thumbnails too, if really needed.
#RewriteRule ^files/medium/(.*)$ /access/files/medium/$1 [P]
#RewriteRule ^files/square/(.*)$ /access/files/square/$1 [P]
```

#### When Omeka S is installed in a sub-path (https://example.com/digital-library/)

Insert the following lines at line 4 of [.htaccess], just after `RewriteEngine On`,
adapting it to your real config (here, the sub-path is `digital-library`):

```Apache
RewriteRule ^files/original/(.*)$ /digital-library/access/files/original/$1 [P]
RewriteRule ^files/large/(.*)$ /digital-library/access/files/large/$1 [P]
# Uncomment the lines below to protect square and medium thumbnails too, if really needed.
#RewriteRule ^files/medium/(.*)$ /digital-library/access/files/medium/$1 [P]
#RewriteRule ^files/square/(.*)$ /digital-library/access/files/square/$1 [P]

RewriteCond %{REQUEST_URI}::$1 ^(/.+)/(.*)::/digital-library/access/\2$
RewriteRule ^(.*) - [E=BASE:%1]
```

#### Common issues

- Enable to redirect to a virtual proxy with https

  The config below uses flag `[P]` for an internal fake `Proxy`, so Apache
  rewrites the path like a proxy. So if there is a redirection to a secured
  server (https), the certificate should be running and up-to-date and the
  option `SSLProxyEngine on` should be set in the Apache config of the web
  server. Anyway, if you have access to it, you can include all rules inside it
  directly. If you don't have access to the Apache config, just use the full
  unsecure url with `http://` for the internal proxy:

  ```Apache
  RewriteRule ^files/original/(.*)$ http://example.org/digital-library/access/files/original/$1 [P]
  RewriteRule ^files/large/(.*)$ http://example.org/digital-library/access/files/large/$1 [P]
  ```

  Because it is an fake proxy, it doesn't matter if the url is `http://` only.

### Identification of the restricted medias

After the configuration, you should identify all medias that you want to make
available via a restricted access. By default, private resources remain private,
so you need to allow visitors to know that they exist. That is to say you can
keep some private resources private, and some other ones available on request,
or globally.

To indicate which resources are restricted, simply add a value to the property
`curation:reservedAccess`, that is created by the module. When a private
resource has a value for this property, whatever it is (except an empty value),
it becomes available for all guest users, and all visitors can view it
automatically too in listings. Preview will be available for media too.

### Management of requests

If you choose the global mode, there is nothing to do more. Once users are
authenticated as guest, they will be able to see the files.

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

- [ ] Make resources available by token in global mode.
- [ ] Make resources available by token only, not login (like module Contribute).
- [ ] Use Omeka Store instead of local file system.


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

* Copyright Daniel Berthereau, 2019-2021 (see [Daniel-KM] on GitLab)
* Copyright Saki (image [Locked file], see [Saki])


[Access Resource]: https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource
[Omeka S]: https://omeka.org/s
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Blocks Disposition]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlocksDisposition
[Contact Us]: https://gitlab.com/Daniel-KM/Omeka-S-module-ContactUs
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
