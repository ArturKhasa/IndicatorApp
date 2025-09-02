FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
      apt-utils \
      libpq-dev \
      postgresql-client \
      postgresql-client-common \
      libzip-dev \
      supervisor \
      zip unzip \
      git && \
      apt-get install -y libxml2-dev && docker-php-ext-install soap &&\
      docker-php-ext-install pdo_pgsql pdo pdo_pgsql pgsql zip exif pcntl calendar && \
      docker-php-ext-configure pgsql && \
      docker-php-ext-install bcmath && \
      docker-php-ext-install zip && \
      docker-php-ext-install sockets && \
      apt-get clean && \
      rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN apt-get update && apt-get install -y \
		libfreetype-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) gd

RUN docker-php-ext-install bcmath

# xdebug
#COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
#RUN install-php-extensions xdebug
#ENV PHP_IDE_CONFIG 'serverName=School_server'
#RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
#RUN echo "xdebug.start_with_request = yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
#RUN echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
#RUN echo "xdebug.client_port=9001" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
#RUN echo "xdebug.log=/var/log/xdebug.log" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
#RUN echo "xdebug.idekey = PHPSTORM" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

COPY ./_docker/app/php.ini /usr/local/etc/php/conf.d/php.ini
COPY ./_docker/supervisor/supervisord.conf /etc/supervisor

RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
RUN apt-get install -y nodejs

RUN apt-get install -y tzdata
ENV TZ=Europe/Moscow

RUN pecl install redis \
       && docker-php-ext-enable redis
# Install composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- \
    --filename=composer \
    --install-dir=/usr/local/bin

RUN chmod +x /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www

CMD ["/usr/bin/supervisord",  "-c",  "/etc/supervisor/supervisord.conf"]
USER root
