<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Browser;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverSelect;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Form;

final readonly class BackendBrowser
{
    public function __construct(private Client $client)
    {
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function waitFor(string $selector): void
    {
        $this->client->waitFor($selector);
    }

    public function submitLogin(string $username, string $password): void
    {
        $form = $this->client->getCrawler()->filter('button[name="login"]')->form([
            'username' => $username,
            'password' => $password,
        ]);
        $this->submit($form);
    }

    /**
     * @param array<string, string> $values
     */
    public function submitForm(string $button, array $values = []): void
    {
        $this->waitForBackend();
        $buttons = $this->client->getCrawler()->filter('button, input[type="submit"]');

        foreach ($buttons as $index => $element) {
            $label = trim($element->getText()) ?: (string) $element->getAttribute('value');

            if ($button === $label && $element->isDisplayed()) {
                $this->submit($buttons->eq($index)->form($values));

                return;
            }
        }

        throw new \LogicException(\sprintf('Could not find a visible submit button labeled "%s".', $button));
    }

    public function submitNew(): void
    {
        $this->waitForBackend();
        $action = $this->client->waitFor('.header_new')->filter('.header_new');
        $element = $action->getElement(0);

        if ('a' === $element->getTagName()) {
            $this->client->request('GET', (string) $element->getAttribute('href'));

            return;
        }

        $this->submit($action->form());
    }

    public function submitAction(string $label): void
    {
        $this->waitForBackend();
        $buttons = $this->client->getCrawler()->filter('button[type="submit"]');

        foreach ($buttons as $index => $element) {
            $button = $buttons->eq($index);
            $image = $button->filter('img[alt]');
            $descriptions = [
                trim($element->getText()),
                (string) $element->getAttribute('title'),
                $image->count() ? (string) $image->attr('alt') : '',
            ];

            if ($element->isDisplayed() && str_contains(implode("\0", $descriptions), $label)) {
                $this->submit($button->form());

                return;
            }
        }

        throw new \LogicException(\sprintf('Could not find a submit action labeled "%s".', $label));
    }

    public function check(string $field): void
    {
        $this->waitForBackend();
        $checkboxes = $this->client->getCrawler()->filter(\sprintf('input[name="%s"][type="checkbox"]', $field));

        foreach ($checkboxes as $checkbox) {
            if ($checkbox->isDisplayed()) {
                $checkbox->sendKeys(WebDriverKeys::SPACE);

                if (!$checkbox->isSelected()) {
                    throw new \LogicException(\sprintf('Could not check the "%s" checkbox.', $field));
                }

                $this->client->refreshCrawler();

                return;
            }
        }

        throw new \LogicException(\sprintf('Could not find the "%s" checkbox.', $field));
    }

    public function select(string $field, string $value): void
    {
        $this->waitForBackend();
        $select = $this->client->getCrawler()->filter(\sprintf('select[name="%s"]', $field));

        if (!$select->count()) {
            throw new \LogicException(\sprintf('Could not find the "%s" select.', $field));
        }

        new WebDriverSelect($select->getElement(0))->selectByValue($value);
        $this->client->refreshCrawler();
    }

    public function clickLink(string $label): void
    {
        $selector = WebDriverBy::xpath(\sprintf('//a[normalize-space(.)=%s]', $this->xpathLiteral($label)));
        $link = $this->client->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated($selector));
        $this->client->request('GET', (string) $link->getAttribute('href'));
    }

    public function clickButton(string $selector): void
    {
        $this->waitForBackend();
        $button = $this->client->waitForVisibility($selector)->filter($selector);
        $button->getElement(0)->click();
        $this->client->refreshCrawler();
    }

    public function clickTitlePrefix(string $title): void
    {
        $this->waitForBackend();
        $selector = \sprintf('a[title^="%s"]', $this->escapeCssString($title));
        $link = $this->client->waitFor($selector)->filter($selector)->getElement(0);
        $this->client->request('GET', (string) $link->getAttribute('href'));
    }

    public function fillRichText(string $field, string $text): void
    {
        $this->waitForBackend();
        $driver = $this->client->getWebDriver();
        $frame = $this->client->wait()->until(WebDriverExpectedCondition::presenceOfElementLocated(
            WebDriverBy::id('ctrl_'.$field.'_ifr'),
        ));
        $driver->switchTo()->frame($frame);
        $editor = $this->client->wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::id('tinymce'),
        ));
        $editor->clear();
        $editor->sendKeys($text);
        $driver->switchTo()->defaultContent();
        $this->client->refreshCrawler();
    }

    public function selectFile(string $field, string $path, string|null $expectedValue = null): void
    {
        $this->waitForBackend();
        $driver = $this->client->getWebDriver();
        $driver->findElement(WebDriverBy::id('ft_'.$field))->click();
        $frame = $this->client->wait()->until(WebDriverExpectedCondition::presenceOfElementLocated(
            WebDriverBy::cssSelector('iframe[name="simple-modal-iframe"]'),
        ));
        $driver->switchTo()->frame($frame);
        $this->expandFileTree(\dirname($path));
        $this->client->wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector(\sprintf('input[type="radio"][value="%s"]', $this->escapeCssString($path))),
        ))->click();
        $driver->switchTo()->defaultContent();
        $this->client->wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('.simple-modal .btn.primary'),
        ))->click();
        $this->waitForFileSelection($field, $expectedValue);
        $this->client->refreshCrawler();
    }

    private function expandFileTree(string $directory): void
    {
        $parts = explode('/', $directory);

        for ($i = 2; $i <= \count($parts); ++$i) {
            $path = implode('/', \array_slice($parts, 0, $i));
            $selector = WebDriverBy::cssSelector(\sprintf('li[data-id="%s"] a.foldable', $this->escapeCssString($path)));
            $folder = $this->client->wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($selector));

            if (!str_contains((string) $folder->getAttribute('class'), 'foldable--open')) {
                $this->client->wait()->until(WebDriverExpectedCondition::elementToBeClickable($selector))->click();
            }
        }
    }

    private function waitForFileSelection(string $field, string|null $expectedValue): void
    {
        if (null === $expectedValue) {
            $this->client->waitForInvisibility('.simple-modal');

            return;
        }

        $this->client->wait()->until(WebDriverExpectedCondition::elementValueContains(
            WebDriverBy::id('ctrl_'.$field),
            $expectedValue,
        ));
    }

    private function submit(Form $form): void
    {
        $element = $form->getElement();
        $button = $form->getButton();
        !$button ? $element->submit() : $button->click();
        $this->client->wait()->until(WebDriverExpectedCondition::stalenessOf($element));
        $this->client->refreshCrawler();
    }

    private function escapeCssString(string $value): string
    {
        return addcslashes($value, "\\\"\n\r\f");
    }

    private function waitForBackend(): void
    {
        $this->client->waitFor('body.js');
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (!str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        return "concat('".implode("', \"'\", '", explode("'", $value))."')";
    }
}
