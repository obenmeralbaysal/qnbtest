FROM php:8.2.35-apache

COPY docker/apache.conf /etc/apache2/conf-enabled/qnb.conf
