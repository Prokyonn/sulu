<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PageBundle\Tests\Functional\Controller;

use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ExcerptControllerTest extends SuluTestCase
{
    /**
     * @var SessionInterface
     */
    private $session;

    public function setUp()
    {
        parent::setUp();
        $this->session = $this->getPhpcrDefaultSession();
        $this->initPhpcr();
    }

    public function testPutAndGet()
    {
        $client = $this->createAuthenticatedClient();
        $webspaceUuid = $this->session->getNode('/cmf/sulu_io/contents')->getIdentifier();

        $client->request('PUT', '/api/page-excerpts/' . $webspaceUuid . '?locale=en&webspace=sulu_io', [
            'title' => 'Excerpt Title',
        ]);
        $this->assertHttpStatusCode(200, $client->getResponse());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals('Excerpt Title', $response->title);
        $this->assertEquals(false, $response->publishedState);
        $this->assertObjectHasAttribute('published', $response);

        $client->request('GET', '/api/page-excerpts/' . $webspaceUuid . '?locale=en&webspace=sulu_io');
        $this->assertHttpStatusCode(200, $client->getResponse());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals('Excerpt Title', $response->title);
        $this->assertEquals(false, $response->publishedState);
        $this->assertObjectHasAttribute('published', $response);
    }

    public function testPutWithNullValues()
    {
        $client = $this->createAuthenticatedClient();
        $webspaceUuid = $this->session->getNode('/cmf/sulu_io/contents')->getIdentifier();

        $client->request('PUT', '/api/page-excerpts/' . $webspaceUuid . '?locale=en&webspace=sulu_io', [
            'title' => 'Excerpt Title',
        ]);
        $this->assertHttpStatusCode(200, $client->getResponse());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals('Excerpt Title', $response->title);

        $client->request('PUT', '/api/page-excerpts/' . $webspaceUuid . '?locale=en&webspace=sulu_io', [
            'title' => null,
        ]);
        $this->assertHttpStatusCode(200, $client->getResponse());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals('', $response->title);
    }

    public function testPutAndGetPublished()
    {
        $client = $this->createAuthenticatedClient();
        $webspaceUuid = $this->session->getNode('/cmf/sulu_io/contents')->getIdentifier();

        $client->request('PUT', '/api/page-excerpts/' . $webspaceUuid . '?action=publish&locale=en&webspace=sulu_io', [
            'title' => 'Excerpt Title',
        ]);
        $this->assertHttpStatusCode(200, $client->getResponse());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals('Excerpt Title', $response->title);
        $this->assertEquals(true, $response->publishedState);
        $this->assertObjectHasAttribute('published', $response);

        $client->request('GET', '/api/page-excerpts/' . $webspaceUuid . '?locale=en&webspace=sulu_io');
        $this->assertHttpStatusCode(200, $client->getResponse());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals('Excerpt Title', $response->title);
        $this->assertEquals(true, $response->publishedState);
        $this->assertObjectHasAttribute('published', $response);
    }
}
