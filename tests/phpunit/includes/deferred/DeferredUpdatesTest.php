<?php

use MediaWiki\Deferred\DeferrableUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\MergeableUpdate;
use MediaWiki\Deferred\MWCallableUpdate;
use MediaWiki\Deferred\TransactionRoundDefiningUpdate;
use MediaWiki\Logger\LoggerFactory;

/**
 * @group Database
 * @covers DeferredUpdates
 * @covers DeferredUpdatesScopeStack
 * @covers DeferredUpdatesScope
 */
class DeferredUpdatesTest extends MediaWikiIntegrationTestCase {

	public function testAddAndRun() {
		$update = $this->getMockBuilder( DeferrableUpdate::class )
			->onlyMethods( [ 'doUpdate' ] )->getMock();
		$update->expects( $this->once() )->method( 'doUpdate' );

		DeferredUpdates::addUpdate( $update );
		DeferredUpdates::doUpdates();
	}

	public function testAddMergeable() {
		$this->setMwGlobals( 'wgCommandLineMode', false );

		$update1 = $this->getMockBuilder( MergeableUpdate::class )
			->onlyMethods( [ 'merge', 'doUpdate' ] )->getMock();
		$update1->expects( $this->once() )->method( 'merge' );
		$update1->expects( $this->never() )->method( 'doUpdate' );

		$update2 = $this->getMockBuilder( MergeableUpdate::class )
			->onlyMethods( [ 'merge', 'doUpdate' ] )->getMock();
		$update2->expects( $this->never() )->method( 'merge' );
		$update2->expects( $this->never() )->method( 'doUpdate' );

		DeferredUpdates::addUpdate( $update1 );
		DeferredUpdates::addUpdate( $update2 );
	}

	public function testAddCallableUpdate() {
		$this->setMwGlobals( 'wgCommandLineMode', true );

		$ran = 0;
		DeferredUpdates::addCallableUpdate( static function () use ( &$ran ) {
			$ran++;
		} );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $ran, 'Update ran' );
	}

	public function testGetPendingUpdates() {
		// Prevent updates from running
		$this->setMwGlobals( 'wgCommandLineMode', false );

		$pre = DeferredUpdates::PRESEND;
		$post = DeferredUpdates::POSTSEND;
		$all = DeferredUpdates::ALL;

		$update = $this->createMock( DeferrableUpdate::class );
		$update->expects( $this->never() )
			->method( 'doUpdate' );

		DeferredUpdates::addUpdate( $update, $pre );
		$this->assertCount( 1, DeferredUpdates::getPendingUpdates( $pre ) );
		$this->assertSame( [], DeferredUpdates::getPendingUpdates( $post ) );
		$this->assertCount( 1, DeferredUpdates::getPendingUpdates( $all ) );
		$this->assertCount( 1, DeferredUpdates::getPendingUpdates() );
		DeferredUpdates::clearPendingUpdates();
		$this->assertSame( [], DeferredUpdates::getPendingUpdates() );

		DeferredUpdates::addUpdate( $update, $post );
		$this->assertSame( [], DeferredUpdates::getPendingUpdates( $pre ) );
		$this->assertCount( 1, DeferredUpdates::getPendingUpdates( $post ) );
		$this->assertCount( 1, DeferredUpdates::getPendingUpdates( $all ) );
		$this->assertCount( 1, DeferredUpdates::getPendingUpdates() );
		DeferredUpdates::clearPendingUpdates();
		$this->assertSame( [], DeferredUpdates::getPendingUpdates() );
	}

	public function testDoUpdatesWeb() {
		$this->setMwGlobals( 'wgCommandLineMode', false );

		$updates = [
			'1' => "deferred update 1;\n",
			'2' => "deferred update 2;\n",
			'2-1' => "deferred update 1 within deferred update 2;\n",
			'2-2' => "deferred update 2 within deferred update 2;\n",
			'3' => "deferred update 3;\n",
			'3-1' => "deferred update 1 within deferred update 3;\n",
			'3-2' => "deferred update 2 within deferred update 3;\n",
			'3-1-1' => "deferred update 1 within deferred update 1 within deferred update 3;\n",
			'3-2-1' => "deferred update 1 within deferred update 2 with deferred update 3;\n",
		];
		DeferredUpdates::addCallableUpdate(
			static function () use ( $updates ) {
				echo $updates['1'];
			}
		);
		DeferredUpdates::addCallableUpdate(
			static function () use ( $updates ) {
				echo $updates['2'];
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['2-1'];
					}
				);
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['2-2'];
					}
				);
			}
		);
		DeferredUpdates::addCallableUpdate(
			static function () use ( $updates ) {
				echo $updates['3'];
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['3-1'];
						DeferredUpdates::addCallableUpdate(
							static function () use ( $updates ) {
								echo $updates['3-1-1'];
							}
						);
					}
				);
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['3-2'];
						DeferredUpdates::addCallableUpdate(
							static function () use ( $updates ) {
								echo $updates['3-2-1'];
							}
						);
					}
				);
			}
		);

		$this->assertEquals( 3, DeferredUpdates::pendingUpdatesCount() );

		$this->expectOutputString( implode( '', $updates ) );

		DeferredUpdates::doUpdates();

		$x = null;
		$y = null;
		DeferredUpdates::addCallableUpdate(
			static function () use ( &$x ) {
				$x = 'Sherity';
			},
			DeferredUpdates::PRESEND
		);
		DeferredUpdates::addCallableUpdate(
			static function () use ( &$y ) {
				$y = 'Marychu';
			},
			DeferredUpdates::POSTSEND
		);

		$this->assertNull( $x, "Update not run yet" );
		$this->assertNull( $y, "Update not run yet" );

		DeferredUpdates::doUpdates( DeferredUpdates::PRESEND );
		$this->assertEquals( "Sherity", $x, "PRESEND update ran" );
		$this->assertNull( $y, "POSTSEND update not run yet" );

		DeferredUpdates::doUpdates( DeferredUpdates::POSTSEND );
		$this->assertEquals( "Marychu", $y, "POSTSEND update ran" );
	}

	public function testDoUpdatesCLI() {
		$this->setMwGlobals( 'wgCommandLineMode', true );
		$updates = [
			'1' => "deferred update 1;\n",
			'2' => "deferred update 2;\n",
			'2-1' => "deferred update 1 within deferred update 2;\n",
			'2-2' => "deferred update 2 within deferred update 2;\n",
			'3' => "deferred update 3;\n",
			'3-1' => "deferred update 1 within deferred update 3;\n",
			'3-2' => "deferred update 2 within deferred update 3;\n",
			'3-1-1' => "deferred update 1 within deferred update 1 within deferred update 3;\n",
			'3-2-1' => "deferred update 1 within deferred update 2 with deferred update 3;\n",
		];

		// clear anything
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->commitPrimaryChanges( __METHOD__ );

		DeferredUpdates::addCallableUpdate(
			static function () use ( $updates ) {
				echo $updates['1'];
			}
		);
		DeferredUpdates::addCallableUpdate(
			static function () use ( $updates ) {
				echo $updates['2'];
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['2-1'];
					}
				);
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['2-2'];
					}
				);
			}
		);
		DeferredUpdates::addCallableUpdate(
			static function () use ( $updates ) {
				echo $updates['3'];
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['3-1'];
						DeferredUpdates::addCallableUpdate(
							static function () use ( $updates ) {
								echo $updates['3-1-1'];
							}
						);
					}
				);
				DeferredUpdates::addCallableUpdate(
					static function () use ( $updates ) {
						echo $updates['3-2'];
						DeferredUpdates::addCallableUpdate(
							static function () use ( $updates ) {
								echo $updates['3-2-1'];
							}
						);
					}
				);
			}
		);

		$this->expectOutputString( implode( '', $updates ) );

		DeferredUpdates::doUpdates();
	}

	public function testPresendAddOnPostsendRun() {
		$this->setMwGlobals( 'wgCommandLineMode', true );

		$x = false;
		$y = false;
		// clear anything
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->commitPrimaryChanges( __METHOD__ );

		DeferredUpdates::addCallableUpdate(
			static function () use ( &$x, &$y ) {
				$x = true;
				DeferredUpdates::addCallableUpdate(
					static function () use ( &$y ) {
						$y = true;
					},
					DeferredUpdates::PRESEND
				);
			},
			DeferredUpdates::POSTSEND
		);

		DeferredUpdates::doUpdates();

		$this->assertTrue( $x, "Outer POSTSEND update ran" );
		$this->assertTrue( $y, "Nested PRESEND update ran" );
	}

	public function testRunUpdateTransactionScope() {
		$this->setMwGlobals( 'wgCommandLineMode', false );

		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$this->assertFalse( $lbFactory->hasTransactionRound(), 'Initial state' );

		$ran = 0;
		DeferredUpdates::addCallableUpdate( function () use ( &$ran, $lbFactory ) {
			$ran++;
			$this->assertTrue( $lbFactory->hasTransactionRound(), 'Has transaction' );
		} );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $ran, 'Update ran' );
		$this->assertFalse( $lbFactory->hasTransactionRound(), 'Final state' );
	}

	/**
	 * @covers TransactionRoundDefiningUpdate
	 */
	public function testRunOuterScopeUpdate() {
		$this->setMwGlobals( 'wgCommandLineMode', false );

		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$this->assertFalse( $lbFactory->hasTransactionRound(), 'Initial state' );

		$ran = 0;
		DeferredUpdates::addUpdate( new TransactionRoundDefiningUpdate(
				function () use ( &$ran, $lbFactory ) {
					$ran++;
					$this->assertFalse( $lbFactory->hasTransactionRound(), 'No transaction' );
				} )
		);
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $ran, 'Update ran' );
	}

	public function testTryOpportunisticExecute() {
		$calls = [];
		$callback1 = static function () use ( &$calls ) {
			$calls[] = 1;
		};
		$callback2 = static function () use ( &$calls ) {
			$calls[] = 2;
		};

		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->beginPrimaryChanges( __METHOD__ );

		DeferredUpdates::addCallableUpdate( $callback1 );
		$this->assertEquals( [], $calls );

		DeferredUpdates::tryOpportunisticExecute();
		$this->assertEquals( [], $calls );

		$dbw = $this->getDb();
		$dbw->onTransactionCommitOrIdle( function () use ( &$calls, $callback2 ) {
			DeferredUpdates::addCallableUpdate( $callback2 );
			$this->assertEquals( [], $calls );
			$calls[] = 'oti';
		} );
		$this->assertSame( 1, $dbw->trxLevel() );
		$this->assertEquals( [], $calls );

		$lbFactory->commitPrimaryChanges( __METHOD__ );

		$this->assertEquals( [ 'oti' ], $calls );

		DeferredUpdates::tryOpportunisticExecute();
		$this->assertEquals( [ 'oti', 1, 2 ], $calls );
	}

	public function testTryOpportunisticExecute_enqueue() {
		$this->setLogger( 'DeferredUpdates', new TestLogger( true, null, true ) );
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->beginPrimaryChanges( __METHOD__ );
		for ( $i = 1; $i <= 50; $i++ ) {
			DeferredUpdates::addCallableUpdate( fn () => null );
		}
		$enqueueableUpdate = new class ( fn () => null, $this->getDb()->getDomainID() )
			extends MWCallableUpdate
			implements EnqueueableDataUpdate
		{
			private $domainId;

			public function __construct( callable $callback, $domainId ) {
				parent::__construct( $callback );
				$this->domainId = $domainId;
			}

			public function getAsJobSpecification() {
				return [ 'domain' => $this->domainId, 'job' => new JobSpecification( 'foo', [] ) ];
			}
		};
		DeferredUpdates::addUpdate( $enqueueableUpdate );
		for ( $i = 1; $i <= 50; $i++ ) {
			DeferredUpdates::addCallableUpdate( fn () => null );
		}
		DeferredUpdates::tryOpportunisticExecute();
		$lbFactory->commitPrimaryChanges( __METHOD__ );

		$log = LoggerFactory::getInstance( 'DeferredUpdates' )->getBuffer();
		$this->assertSame( 'Enqueued {enqueuedUpdatesCount} updates as jobs', $log[0][1] );
		$this->assertSame( 1, $log[0][2]['enqueuedUpdatesCount'] );
		$this->assertSame( get_class( $enqueueableUpdate ) . ': 1', $log[0][2]['enqueuedUpdates'] );
	}

	/**
	 * @covers MWCallableUpdate
	 */
	public function testCallbackUpdateRounds() {
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();

		$fname = __METHOD__;
		$called = false;
		// This confirms that DeferredUpdates sets the transaction owner in LBFactory
		// based on MWCallableUpdate::getOrigin, thus allowing the callback to control
		// over the transaction and e.g. perform a commit.
		DeferredUpdates::attemptUpdate(
			new MWCallableUpdate(
				static function () use ( $lbFactory, $fname, &$called ) {
					$lbFactory->flushReplicaSnapshots( $fname );
					$lbFactory->commitPrimaryChanges( $fname );
					$called = true;
				},
				$fname
			)
		);

		$this->assertTrue( $called, "Callback ran" );
	}

	public function testNestedExecution() {
		// No immediate execution
		$this->setMwGlobals( 'wgCommandLineMode', false );

		$res = null;
		$resSub = null;
		$resSubSub = null;
		$resA = null;

		DeferredUpdates::clearPendingUpdates();

		$this->assertSame( 0, DeferredUpdates::pendingUpdatesCount() );
		$this->assertSame( 0, DeferredUpdates::getRecursiveExecutionStackDepth() );

		// T249069: TransactionRoundDefiningUpdate => JobRunner => DeferredUpdates::doUpdates()
		DeferredUpdates::addUpdate( new TransactionRoundDefiningUpdate(
			function () use ( &$res, &$resSub, &$resSubSub, &$resA ) {
				$res = 1;

				$this->assertSame( 0, DeferredUpdates::pendingUpdatesCount() );
				$this->assertSame( 1, DeferredUpdates::getRecursiveExecutionStackDepth() );

				// Add update to subqueue of in-progress top-queue job
				DeferredUpdates::addCallableUpdate( function () use ( &$resSub, &$resSubSub ) {
					$resSub = 'a';

					$this->assertSame( 0, DeferredUpdates::pendingUpdatesCount() );
					$this->assertSame( 2, DeferredUpdates::getRecursiveExecutionStackDepth() );

					// Add update to subqueue of in-progress top-queue job (not recursive)
					DeferredUpdates::addCallableUpdate( static function () use ( &$resSubSub ) {
						$resSubSub = 'b';
					} );

					$this->assertSame( 1, DeferredUpdates::pendingUpdatesCount() );
				} );

				$this->assertSame( 1, DeferredUpdates::pendingUpdatesCount() );
				$this->assertSame( 1, DeferredUpdates::getRecursiveExecutionStackDepth() );

				if ( $resSub === null && $resA === null && $resSubSub === null ) {
					$res = 418;
				}

				DeferredUpdates::doUpdates();
			}
		) );

		$this->assertSame( 1, DeferredUpdates::pendingUpdatesCount() );
		$this->assertSame( 0, DeferredUpdates::getRecursiveExecutionStackDepth() );

		DeferredUpdates::addCallableUpdate( static function () use ( &$resA ) {
			$resA = 93;
		} );

		$this->assertSame( 2, DeferredUpdates::pendingUpdatesCount() );
		$this->assertSame( 0, DeferredUpdates::getRecursiveExecutionStackDepth() );

		$this->assertNull( $resA );
		$this->assertNull( $res );
		$this->assertNull( $resSub );
		$this->assertNull( $resSubSub );

		DeferredUpdates::doUpdates();

		$this->assertSame( 0, DeferredUpdates::pendingUpdatesCount() );
		$this->assertSame( 0, DeferredUpdates::getRecursiveExecutionStackDepth() );
		$this->assertSame( 418, $res );
		$this->assertSame( 'a', $resSub );
		$this->assertSame( 'b', $resSubSub );
		$this->assertSame( 93, $resA );
	}
}
