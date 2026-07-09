<?php

namespace PhanAn\Poddle\Exceptions;

class InvalidSoundbiteElementException extends InvalidElementException
{
    protected function specUrl(): string
    {
        return 'https://github.com/Podcast-Standards-Project/PSP-1-Podcast-RSS-Specification#item-podcast-soundbite';
    }

    protected function elementName(): string
    {
        return '<podcast:soundbite>';
    }
}
