<?php
/**
 * @author Clark Tomlinson <fallen013@gmail.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OCA\Encryption\Tests;


use OCA\Encryption\Recovery;
use Test\TestCase;

class RecoveryTest extends TestCase {
	private static $tempStorage = [];
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject
	 */
	private $fileMock;
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject
	 */
	private $viewMock;
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject
	 */
	private $userSessionMock;
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject
	 */
	private $keyManagerMock;
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject
	 */
	private $configMock;
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject
	 */
	private $cryptMock;
	/**
	 * @var Recovery
	 */
	private $instance;

	public function testEnableAdminRecovery() {
		$this->keyManagerMock->expects($this->exactly(2))
			->method('recoveryKeyExists')
			->willReturnOnConsecutiveCalls(false, true);

		$this->cryptMock->expects($this->once())
			->method('createKeyPair')
			->willReturn(true);

		$this->keyManagerMock->expects($this->once())
			->method('setRecoveryKey')
			->willReturn(false);

		$this->keyManagerMock->expects($this->exactly(2))
			->method('checkRecoveryPassword')
			->willReturnOnConsecutiveCalls(true, false);

		$this->assertTrue($this->instance->enableAdminRecovery('password'));
		$this->assertArrayHasKey('recoveryAdminEnabled', self::$tempStorage);
		$this->assertEquals(1, self::$tempStorage['recoveryAdminEnabled']);

		$this->assertFalse($this->instance->enableAdminRecovery('password'));
	}

	public function testChangeRecoveryKeyPassword() {
		$this->assertFalse($this->instance->changeRecoveryKeyPassword('password',
			'passwordOld'));

		$this->keyManagerMock->expects($this->once())
			->method('getSystemPrivateKey');

		$this->cryptMock->expects($this->once())
			->method('decryptPrivateKey');

		$this->cryptMock->expects($this->once())
			->method('symmetricEncryptFileContent')
			->willReturn(true);

		$this->assertTrue($this->instance->changeRecoveryKeyPassword('password',
			'passwordOld'));
	}

	public function testDisableAdminRecovery() {

		$this->keyManagerMock->expects($this->exactly(2))
			->method('checkRecoveryPassword')
			->willReturnOnConsecutiveCalls(true, false);

		$this->assertArrayHasKey('recoveryAdminEnabled', self::$tempStorage);
		$this->assertTrue($this->instance->disableAdminRecovery('password'));
		$this->assertEquals(0, self::$tempStorage['recoveryAdminEnabled']);

		$this->assertFalse($this->instance->disableAdminRecovery('password'));
	}

	public function testIsRecoveryEnabledForUser() {

		$this->configMock->expects($this->exactly(2))
			->method('getUserValue')
			->willReturnOnConsecutiveCalls('1', '0');

		$this->assertTrue($this->instance->isRecoveryEnabledForUser());
		$this->assertFalse($this->instance->isRecoveryEnabledForUser('admin'));
	}

	public function testIsRecoveryKeyEnabled() {
		$this->assertFalse($this->instance->isRecoveryKeyEnabled());
		self::$tempStorage['recoveryAdminEnabled'] = '1';
		$this->assertTrue($this->instance->isRecoveryKeyEnabled());
	}

	public function testSetRecoveryFolderForUser() {
		$this->viewMock->expects($this->exactly(2))
			->method('getDirectoryContent')
			->willReturn([]);
		$this->assertTrue($this->instance->setRecoveryForUser(0));
		$this->assertTrue($this->instance->setRecoveryForUser('1'));
	}

	public function testRecoverUserFiles() {
		$this->viewMock->expects($this->once())
			->method('getDirectoryContent')
			->willReturn([]);

		$this->cryptMock->expects($this->once())
			->method('decryptPrivateKey');
		$this->assertNull($this->instance->recoverUsersFiles('password',
			'admin'));
	}

	public function testRecoverFile() {
		$this->keyManagerMock->expects($this->once())
			->method('getEncryptedFileKey')
			->willReturn(true);

		$this->keyManagerMock->expects($this->once())
			->method('getShareKey')
			->willReturn(true);

		$this->cryptMock->expects($this->once())
			->method('multiKeyDecrypt')
			->willReturn(true);

		$this->fileMock->expects($this->once())
			->method('getAccessList')
			->willReturn(['users' => ['admin']]);

		$this->keyManagerMock->expects($this->once())
			->method('getPublicKey')
			->willReturn('publicKey');

		$this->keyManagerMock->expects($this->once())
			->method('addSystemKeys')
			->willReturn(['admin' => 'publicKey']);


		$this->cryptMock->expects($this->once())
			->method('multiKeyEncrypt');

		$this->keyManagerMock->expects($this->once())
			->method('setAllFileKeys');

		$this->assertNull(\Test_Helper::invokePrivate($this->instance,
			'recoverFile',
			['/', 'testkey']));
	}

	protected function setUp() {
		parent::setUp();


		$this->userSessionMock = $this->getMockBuilder('OCP\IUserSession')
			->disableOriginalConstructor()
			->setMethods([
				'isLoggedIn',
				'getUID',
				'login',
				'logout',
				'setUser',
				'getUser'
			])
			->getMock();

		$this->userSessionMock->expects($this->any())->method('getUID')->will($this->returnValue('admin'));

		$this->userSessionMock->expects($this->any())
			->method($this->anything())
			->will($this->returnSelf());

		$this->cryptMock = $this->getMockBuilder('OCA\Encryption\Crypto\Crypt')->disableOriginalConstructor()->getMock();
		$randomMock = $this->getMock('OCP\Security\ISecureRandom');
		$this->keyManagerMock = $this->getMockBuilder('OCA\Encryption\KeyManager')->disableOriginalConstructor()->getMock();
		$this->configMock = $this->getMock('OCP\IConfig');
		$keyStorageMock = $this->getMock('OCP\Encryption\Keys\IStorage');
		$this->fileMock = $this->getMock('OCP\Encryption\IFile');
		$this->viewMock = $this->getMock('OC\Files\View');

		$this->configMock->expects($this->any())
			->method('setAppValue')
			->will($this->returnCallback([$this, 'setValueTester']));

		$this->configMock->expects($this->any())
			->method('getAppValue')
			->will($this->returnCallback([$this, 'getValueTester']));

		$this->instance = new Recovery($this->userSessionMock,
			$this->cryptMock,
			$randomMock,
			$this->keyManagerMock,
			$this->configMock,
			$keyStorageMock,
			$this->fileMock,
			$this->viewMock);
	}


	/**
	 * @param $app
	 * @param $key
	 * @param $value
	 */
	public function setValueTester($app, $key, $value) {
		self::$tempStorage[$key] = $value;
	}

	/**
	 * @param $key
	 */
	public function removeValueTester($key) {
		unset(self::$tempStorage[$key]);
	}

	/**
	 * @param $app
	 * @param $key
	 * @return mixed
	 */
	public function getValueTester($app, $key) {
		if (!empty(self::$tempStorage[$key])) {
			return self::$tempStorage[$key];
		}
		return null;
	}


}
