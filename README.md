Access Resource (module for Omeka S)
====================================

[Access Resource] is a module for [Omeka S] that allows to protect some
resources to be accessed from the anonymous visitors, but nevertheless available
for some guest users, on identification or by token.


Installation
------------

The module depends on module [Guest], so install them first.

See general end user documentation for [installing a module].

* From the zip

Download the last release [`AccessResource.zip`] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development:

If the module was installed from the source, rename the name of the folder of
the module to `AccessResource`.


Usage
-----

The workflow to request access to a reserved resource is this one:

- First, select the private resources that will be available for visitors or
  guest users: by default, private resources remain private, so you need to
  allow visitors to know that they exist. That is to say you can keep some
  private resources private, and some other ones available on request.

  To indicate which resources are available on request, simply add a value to
  the property `curation:reservedAccess`, that is created by the module. When a
  private resource has a value for this property, whatever it is (except an
  empty value), it becomes available for all guest users, and all visitors can
  view it automatically in listings. Preview will be available for media too.

- Second, the visitors can request an access to the resources they want. It can
  be done directly for the guest users, or via a contact form for the anonymous.
  The contact form may be added by the module [Contact Us].

- Third, an admin can manage access of users to requested resources, in other
  words accept or reject the visitors requests. It can be done in the tab
  `Access` added to every Item and Media admin show page, or in the page "Access resources",
  available in  the side bar.

The module allows another access mode, by token. You can find them on access
view/edit page.

In public front-end, a dashboard is added for guest users. The link is available
in the guest user board (`/s/my-site/guest/access-resource`).


Protecting your files
---------------------

Omeka does not manage the requests of the files of the web server (generally
Apache): they are directly served by it, without any control.

To protect the files, you have to tell Apache to redirect the requests to the
files to Omeka, so it can check the rights, before returning a response.

So, to protect files, you can adapt the following code to your needs in a `.htaccess`
file in the `files` directory:
```
Options +FollowSymlinks
RewriteEngine on

RewriteRule ^original/(.*)$ http://www.example.com/access/files/original/$1 [NC,L]
# The file type is "original" by default, but other ones (large…) can be protected too.
#RewriteRule ^large/(.*)$ http://www.example.com/access/files/large/$1 [NC,L]
```

You can adapt `routes.ini` as you wish too.

In this example, all original files will be protected: a check will be done by
the module before to deliver files. If there is no access to a file, a
notification page will be displayed.


TODO
----

- Make resources available by token only, not login (like module Correction).
- Integrate the module [Contact Us] to insert selected values to the form.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
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


Copyright
---------

* Copyright Daniel Berthereau, 2019 (see [Daniel-KM] on GitHub)


[Access Resource]: https://github.com/Daniel-KM/Omeka-S-module-AccessResource
[Omeka S]: https://omeka.org/s
[Generic]: https://github.com/Daniel-KM/Omeka-S-module-Generic
[Guest]: https://github.com/Daniel-KM/Omeka-S-module-Guest
[Contact Us]: https://github.com/Daniel-KM/Omeka-S-module-ContactUs
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[`AccessResource.zip`]: https://github.com/Daniel-KM/Omeka-S-module-AccessResource/releases
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-AccessResource/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
