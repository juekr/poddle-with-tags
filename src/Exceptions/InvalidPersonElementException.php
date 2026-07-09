<?php

namespace PhanAn\Poddle\Exceptions;

class InvalidPersonElementException extends InvalidElementException
{
    protected function specUrl(): string
    {
        return 'https://github.com/Podcast-Standards-Project/PSP-1-Podcast-RSS-Specification#item-podcast-person';
    }

    protected function elementName(): string
    {
        return '<podcast:person>';
    }
}
