<?

namespace Glutio\EmailLinks;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Mail\DriverInterface;
use Flarum\Mail\LogDriver;
use Flarum\Mail\NullDriver;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\MessageBag;
use Swift_Events_EventListener;
use Swift_Mime_SimpleMessage;
use Swift_MimePart;
use Swift_Transport;

class Swift_TransportWrapper implements Swift_Transport
{
  private $transport;
  public function __construct(Swift_Transport $transport)
  {
    $this->transport = $transport;
  }

  public function isStarted()
  {
    return $this->transport->isStarted();
  }
  public function start()
  {
    return $this->transport->start();
  }

  public function stop()
  {
    return $this->transport->stop();
  }

  public function ping()
  {
    return $this->transport->ping();
  }

  private function linkify($text)
  {
    $newText = $text;
    // Url by itself
    $urlPattern = '/(?:^|\s)((?:http|https):\/\/[^\s]+)/';
    $newText = preg_replace($urlPattern, '<a href="$0">$0</a>', $newText);

    // Url in parentheses
    $urlPattern = '/(?:^|\s)\({1}((?:http|https):\/\/[^\s]+)\){1}/';
    $newText = preg_replace($urlPattern, '(<a href="$0">$0</a>)', $newText);

    // Url in brackets
    $urlPattern = '/(?:^|\s)\[{1}((?:http|https):\/\/[^\s]+)\]{1}/';
    $newText = preg_replace($urlPattern, '[<a href="$0">$0</a>]', $newText);

    return $text == $newText ? null : $newText;
  }

  public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
  {
    $hasHtml = $message->getContentType() === 'text/html' || $message->getBodyContentType() === 'text/html';
    $content = '';

    if (!$hasHtml) {
      if ($message->getContentType() === 'text/plain' || $message->getBodyContentType() === 'text/plain') {
        $content = $message->getBody();
      }

      foreach ($message->getChildren() as $part) {
        if ($part instanceof Swift_MimePart) {
          if ($part->getContentType() === 'text/html') {
            $hasHtml = true;
            break; // No need to continue if we find an HTML part
          }
          if ($part->getContentType() === 'text/plain' && empty($content)) {
            $content = $part->getBody();
          }
        }
      }
    }

    // Convert text to HTML if links are present
    $htmlContent = $this->linkify($content);

    // If no HTML part exists and the plain text content has links, create and add an HTML part
    if (!$hasHtml && $htmlContent !== null) {
      $html = '<html><body>' . $htmlContent . '</body></html>';
      $htmlPart = new Swift_MimePart($html, 'text/html');
      $message->attach($htmlPart); // Use 'attach' to add the HTML part
    }

    // Send the message using the configured transport
    return $this->transport->send($message, $failedRecipients);
  }

  public function registerPlugin(Swift_Events_EventListener $plugin)
  {
    return $this->transport->registerPlugin($plugin);
  }
}

class MailDriverWrapper implements DriverInterface
{
  private $driver;
  public function __construct(Container $container)
  {
    $this->driver = $container->make('mail.driver');
  }
  public function availableSettings(): array
  {
    return $this->driver->availableSettings();
  }
  public function validate(SettingsRepositoryInterface $settings, Factory $validator): MessageBag
  {
    return $this->driver->validate($settings, $validator);
  }
  public function canSend(): bool
  {
    return $this->driver->canSend();
  }
  public function buildTransport(SettingsRepositoryInterface $settings): Swift_Transport
  {
    $transport = $this->driver->buildTransport($settings);
    return new Swift_TransportWrapper($transport);
  }
}

class MailServiceProviderWrapper extends AbstractServiceProvider
{
  public function register()
  {
    $this->container->extend('mail.driver', function ($configured, Container $container) {
      if ($configured instanceof NullDriver || $configured instanceof LogDriver) {
        return $configured;
      }
      return new MailDriverWrapper($configured);
    });
  }
}
