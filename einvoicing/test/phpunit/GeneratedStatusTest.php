<?php
/* Copyright (C) 2026 ATM Consulting
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/GeneratedStatusTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for EInvoicing::markEInvoiceGenerated() and the file deduction of
 *                  fetchLastknownInvoiceStatus().
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Tests on the "Generated" status stored once the e-invoice file is written.
 *
 * The extlink records live inside the transaction CommonClassTest rolls back; the e-invoice file is
 * removed in tearDown().
 */
class GeneratedStatusTest extends CommonClassTest
{
	/** @var int Element id used by the records written here: high enough not to collide with a real invoice */
	public const TEST_ELEMENT_ID = 999999002;

	/** @var string Ref used by the records and the file written here */
	public const TEST_REF = 'TEST-GEN-0001';

	/**
	 * Start from a clean record, with the CII protocol so the file path is known.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		parent::setUp();

		$conf->global->EINVOICING_PDP = 'SUPERPDP';
		$conf->global->EINVOICING_PROTOCOL = 'CII';

		// tearDown() deletes the directory of the e-invoice file: never let it resolve outside the invoice output dir.
		if (empty($conf->invoice->multidir_output[$conf->entity])) {
			$this->markTestSkipped('No invoice output directory for entity ' . $conf->entity);
		}

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_extlinks WHERE element_id = " . (int) self::TEST_ELEMENT_ID);
	}

	/**
	 * Remove the e-invoice file and its directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $db;

		$path = (new EInvoicing($db))->getEInvoiceFilePath(self::TEST_REF);
		dol_delete_dir_recursive(dirname($path));

		parent::tearDown();
	}

	/**
	 * @return Facture
	 */
	private function buildInvoice()
	{
		global $db;

		$invoice = new Facture($db);
		$invoice->id = self::TEST_ELEMENT_ID;
		$invoice->ref = self::TEST_REF;

		return $invoice;
	}

	/**
	 * @return void
	 */
	private function writeEInvoiceFile()
	{
		global $db;

		$path = (new EInvoicing($db))->getEInvoiceFilePath(self::TEST_REF);
		dol_mkdir(dirname($path));
		file_put_contents($path, '<xml/>');
	}

	/**
	 * @param 	int 	$syncStatus		Status to store
	 * @return 	void
	 */
	private function storeStatus($syncStatus)
	{
		global $db;

		(new EInvoicing($db))->insertOrUpdateExtLink(self::TEST_ELEMENT_ID, 'facture', '', $syncStatus, self::TEST_REF);
	}

	/**
	 * @return int
	 */
	private function fetchStoredStatus()
	{
		global $db;

		$status = (new EInvoicing($db))->fetchLastknownInvoiceStatus(self::TEST_ELEMENT_ID, self::TEST_REF, 0);

		return $status['code'];
	}

	/**
	 * The card reports "Generated" from the file, the stored status is still "To generate".
	 *
	 * @return void
	 */
	public function testFileDeductionOnlyAppliesWhenAsked()
	{
		global $db;

		$this->storeStatus(EInvoicing::STATUS_NOT_GENERATED);
		$this->writeEInvoiceFile();

		$einvoicing = new EInvoicing($db);
		$this->assertSame(EInvoicing::STATUS_GENERATED, $einvoicing->fetchLastknownInvoiceStatus(self::TEST_ELEMENT_ID, self::TEST_REF)['code']);
		$this->assertSame(EInvoicing::STATUS_NOT_GENERATED, $this->fetchStoredStatus());
	}

	/**
	 * Regression: the file is written before the status, which stayed at "To generate" in database.
	 *
	 * @return void
	 */
	public function testGenerationStoresGeneratedWhenTheFileIsAlreadyWritten()
	{
		global $db;

		$this->storeStatus(EInvoicing::STATUS_NOT_GENERATED);
		$this->writeEInvoiceFile();

		$this->assertGreaterThan(0, (new EInvoicing($db))->markEInvoiceGenerated($this->buildInvoice()));
		$this->assertSame(EInvoicing::STATUS_GENERATED, $this->fetchStoredStatus());
	}

	/**
	 * @return void
	 */
	public function testGenerationStoresGeneratedWhenNoStatusIsStored()
	{
		global $db;

		$this->writeEInvoiceFile();

		$this->assertGreaterThan(0, (new EInvoicing($db))->markEInvoiceGenerated($this->buildInvoice()));
		$this->assertSame(EInvoicing::STATUS_GENERATED, $this->fetchStoredStatus());
	}

	/**
	 * A regeneration must not bring a status already reported by the platform back to "Generated".
	 *
	 * @return void
	 */
	public function testGenerationKeepsAStatusPastGeneration()
	{
		global $db;

		$this->storeStatus(EInvoicing::STATUS_AWAITING_ACK);
		$this->writeEInvoiceFile();

		$this->assertSame(0, (new EInvoicing($db))->markEInvoiceGenerated($this->buildInvoice()));
		$this->assertSame(EInvoicing::STATUS_AWAITING_ACK, $this->fetchStoredStatus());
	}
}
