<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Tests\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\EmailBundle\Controller\AjaxController;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

class AjaxControllerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var MockObject|Session
     */
    private $sessionMock;

    /**
     * @var MockObject|ModelFactory<EmailModel>
     */
    private $modelFactoryMock;

    /**
     * @var MockObject|Container
     */
    private $containerMock;

    /**
     * @var MockObject|EmailModel
     */
    private $modelMock;

    /**
     * @var MockObject|Email
     */
    private $emailMock;

    /**
     * @var AjaxController
     */
    private $controller;

    /**
     * @var MockObject&ManagerRegistry
     */
    private $managerRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionMock      = $this->createMock(Session::class);
        $this->modelFactoryMock = $this->createMock(ModelFactory::class);
        $this->containerMock    = $this->createMock(Container::class);
        $this->modelMock        = $this->createMock(EmailModel::class);
        $this->emailMock        = $this->createMock(Email::class);
        $this->managerRegistry  = $this->createMock(ManagerRegistry::class);
        $this->controller       = new AjaxController($this->managerRegistry);
        $this->controller->setModelFactory($this->modelFactoryMock);
        $this->controller->setContainer($this->containerMock);

        $parameterBag = $this->createMock(ContainerBagInterface::class);
        $parameterBag->expects(self::once())
            ->method('get')
            ->with('kernel.environment')
            ->willReturn('test');
        $this->containerMock->expects(self::once())
            ->method('has')
            ->with('parameter_bag')
            ->willReturn(true);
        $this->containerMock->expects(self::once())
            ->method('get')
            ->with('parameter_bag')
            ->willReturn($parameterBag);
    }

    public function testSendBatchActionWhenNoIdProvided(): void
    {
        $this->modelFactoryMock->expects($this->once())
            ->method('getModel')
            ->with('email')
            ->willReturn($this->modelMock);

        $response = $this->controller->sendBatchAction(new Request([], []));

        $this->assertEquals('{"success":0}', $response->getContent());
    }

    public function testSendBatchActionWhenIdProvidedButEmailNotPublished(): void
    {
        $this->modelFactoryMock->expects($this->once())
            ->method('getModel')
            ->with('email')
            ->willReturn($this->modelMock);

        $this->modelMock->expects($this->once())
            ->method('getEntity')
            ->with(5)
            ->willReturn($this->emailMock);

        $this->modelMock->expects($this->never())
            ->method('sendEmailToLists');

        $this->sessionMock->expects($this->exactly(3))
            ->method('get')
            ->withConsecutive(
                ['mautic.email.send.progress'],
                ['mautic.email.send.stats'],
                ['mautic.email.send.active']
            )
            ->willReturnOnConsecutiveCalls(
                [0, 100],
                ['sent' => 0, 'failed' => 0, 'failedRecipients' => []],
                false
            );

        $this->emailMock->expects($this->once())
            ->method('isPublished')
            ->willReturn(false);

        $request = new Request([], ['id' => 5, 'pending' => 100]);
        $request->setSession($this->sessionMock);
        $response = $this->controller->sendBatchAction($request);
        $expected = '{"success":1,"percent":0,"progress":[0,100],"stats":{"sent":0,"failed":0,"failedRecipients":[]}}';
        $this->assertEquals($expected, $response->getContent());
    }

    public function testSendBatchActionWhenIdProvidedAndEmailIsPublished(): void
    {
        $this->modelFactoryMock->expects($this->once())
            ->method('getModel')
            ->with('email')
            ->willReturn($this->modelMock);

        $this->modelMock->expects($this->once())
            ->method('getEntity')
            ->with(5)
            ->willReturn($this->emailMock);

        $this->modelMock->expects($this->once())
            ->method('sendEmailToLists')
            ->with($this->emailMock, null, 50)
            ->willReturn([50, 0, []]);

        $this->sessionMock->expects($this->exactly(3))
            ->method('get')
            ->withConsecutive(
                ['mautic.email.send.progress'],
                ['mautic.email.send.stats'],
                ['mautic.email.send.active']
            )
            ->willReturn(
                [0, 100],
                ['sent' => 0, 'failed' => 0, 'failedRecipients' => []],
                false
            );

        $this->emailMock->expects($this->once())
            ->method('isPublished')
            ->willReturn(true);

        $request = new Request([], ['id' => 5, 'pending' => 100, 'batchlimit' => 50]);
        $request->setSession($this->sessionMock);
        $response = $this->controller->sendBatchAction($request);
        $expected = '{"success":1,"percent":50,"progress":[50,100],"stats":{"sent":50,"failed":0,"failedRecipients":[]}}';
        $this->assertEquals($expected, $response->getContent());
    }
}
