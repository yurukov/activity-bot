# Using Nginx instead of Apache

requires php8.1-fpm which is a thing that passes requests to PHP

```bash
sudo apt-get install php php-curl
sudo apt-get install php8.1-fpm
sudo systemctl enable php8.1-fpm.service
sudo systemctl start php8.1-fpm.service
sudo systemctl status php8.1-fpm.service
```

then the nginx config should look like this

```bash
server {
    server_name test.example.com;
    listen 80;
    index index.html index.htm index.php;
    root /var/www/test.example.com/;
    
    location / {
      try_files $uri $uri/ @rewrite;
    }
    location @rewrite {
      rewrite ^/(.*)$ /index.php?path=$1 last;
    }
    location ~ \.php$ {
      include fastcgi.conf;
      fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
#     I don't THINK these are needed but computers are oh so very confusing
#     include fastcgi_params;
#     fastcgi_split_path_info ^(.+\.php)(/.+)$;
#     fastcgi_index index.php;
    }
}
```

to test the config, make a file `test.php`

```php
<?php
echo "hello :)";
echo "<br>";
echo "Path is...";
echo $_GET["path"];
```

...and attempt to access it on test.example.com/test.php.
