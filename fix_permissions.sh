#!/bin/bash
# Fix OpenEMR permissions for telehealth module installation

echo "Fixing OpenEMR permissions for telehealth module..."

# Set proper ownership to apache user (used in OpenEMR container)
chown -R apache:apache /var/www/localhost/htdocs/openemr/

# Set directory permissions (755 = rwxr-xr-x)
find /var/www/localhost/htdocs/openemr/ -type d -exec chmod 755 {} +

# Set file permissions (644 = rw-r--r--)
find /var/www/localhost/htdocs/openemr/ -type f -exec chmod 644 {} +

# Specifically ensure the forms directory is writable
chmod 755 /var/www/localhost/htdocs/openemr/interface/forms/
chown apache:apache /var/www/localhost/htdocs/openemr/interface/forms/

echo "Permissions fixed!"
echo "You can now try installing the telehealth module." 