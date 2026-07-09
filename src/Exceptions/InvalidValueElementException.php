<?php

namespace PhanAn\Poddle\Exceptions;

class InvalidValueElementException extends InvalidElementException
{
    protected function specUrl(): string
    {
        return 'https://github.com/Podcast-Standards-Project/PSP-1-Podcast-RSS-Specification#item-podcast-value';
    }

    protected function elementName(): string
    {
        return '<podcast:value>';
    }
}
