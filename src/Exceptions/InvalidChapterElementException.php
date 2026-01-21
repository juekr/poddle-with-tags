<?php

namespace PhanAn\Poddle\Exceptions;

class InvalidChapterElementException extends InvalidElementException
{
    protected function specUrl(): string
    {
        return 'https://github.com/Podcast-Standards-Project/PSP-1-Podcast-RSS-Specification#podcastchapter';
    }

    protected function elementName(): string
    {
        return '<podcast:chapter>';
    }
}
