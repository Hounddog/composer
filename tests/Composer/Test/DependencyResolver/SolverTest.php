<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Composer\Test\DependencyResolver;

use Composer\Repository\ArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\ComposerRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Test\TestCase;

class SolverTest extends TestCase
{
    protected $pool;
    protected $repo;
    protected $repoInstalled;
    protected $request;
    protected $policy;

    public function setUp()
    {
        $this->pool = new Pool;
        $this->repo = new ArrayRepository;
        $this->repoInstalled = new ArrayRepository;

        $this->request = new Request($this->pool);
        $this->policy = new DefaultPolicy;
        $this->solver = new Solver($this->policy, $this->pool, $this->repoInstalled);
    }

    public function testSolverInstallSingle()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverRemoveIfNotInstalled()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
        ));
    }

    public function testInstallNonExistingPackageFails()
    {
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('B', $this->getVersionConstraint('=', '1'));

        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertEquals(1, count($problems));
            $this->assertEquals('The requested package "b" with constraint == 1.0.0.0 could not be found.', (string) $problems[0]);
        }
    }

    public function testSolverInstallSamePackageFromDifferentRepositories()
    {
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $repo1->addPackage($foo1 = $this->getPackage('foo', '1'));
        $repo2->addPackage($foo2 = $this->getPackage('foo', '1'));

        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($repo1);
        $this->pool->addRepository($repo2);

        $this->request->install('foo');

        $this->checkSolverResult(array(
                array('job' => 'install', 'package' => $foo1),
        ));
    }

    public function testSolverInstallWithDeps()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));

        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), 'requires')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallInstalled()
    {
        $this->repoInstalled->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array());
    }

    public function testSolverInstallInstalledWithAlternative()
    {
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->repoInstalled->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array());
    }

    public function testSolverRemoveSingle()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->remove('A');

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
        ));
    }

    public function testSolverRemoveUninstalled()
    {
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->remove('A');

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateDoesOnlyUpdate()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoInstalled->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));
        $this->reposComplete();

        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0.0.0'), 'requires')));

        $this->request->install('A', $this->getVersionConstraint('=', '1.0.0.0'));
        $this->request->install('B', $this->getVersionConstraint('=', '1.1.0.0'));
        $this->request->update('A', $this->getVersionConstraint('=', '1.0.0.0'));
        $this->request->update('B', $this->getVersionConstraint('=', '1.0.0.0'));

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageB, 'to' => $newPackageB),
        ));
    }

    public function testSolverUpdateSingle()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.1'));
        $this->reposComplete();

        $this->request->install('A');
        $this->request->update('A');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $newPackageA),
        ));
    }

    public function testSolverUpdateAll()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoInstalled->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.1'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));

        $packageA->setRequires(array(new Link('A', 'B', null, 'requires')));
        $newPackageA->setRequires(array(new Link('A', 'B', null, 'requires')));

        $this->reposComplete();

        $this->request->install('A');
        $this->request->updateAll();

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageB, 'to' => $newPackageB),
            array('job' => 'update', 'from' => $packageA, 'to' => $newPackageA),
        ));
    }

    public function testSolverUpdateCurrent()
    {
        $this->repoInstalled->addPackage($this->getPackage('A', '1.0'));
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('A');
        $this->request->update('A');

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateOnlyUpdatesSelectedPackage()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoInstalled->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageAnewer = $this->getPackage('A', '1.1'));
        $this->repo->addPackage($packageBnewer = $this->getPackage('B', '1.1'));

        $this->reposComplete();

        $this->request->install('A');
        $this->request->install('B');
        $this->request->update('A');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $packageAnewer),
        ));
    }

    public function testSolverUpdateConstrained()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.2'));
        $this->repo->addPackage($this->getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->install('A', $this->getVersionConstraint('<', '2.0.0.0'));
        $this->request->update('A');

        $this->checkSolverResult(array(array(
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        )));
    }

    public function testSolverUpdateFullyConstrained()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.2'));
        $this->repo->addPackage($this->getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->install('A', $this->getVersionConstraint('<', '2.0.0.0'));
        $this->request->update('A', $this->getVersionConstraint('=', '1.0.0.0'));

        $this->checkSolverResult(array(array(
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        )));
    }

    public function testSolverUpdateFullyConstrainedPrunesInstalledPackages()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoInstalled->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.2'));
        $this->repo->addPackage($this->getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->install('A', $this->getVersionConstraint('<', '2.0.0.0'));
        $this->request->update('A', $this->getVersionConstraint('=', '1.0.0.0'));

        $this->checkSolverResult(array(
            array(
                'job' => 'remove',
                'package' => $packageB,
            ),
            array(
                'job' => 'update',
                'from' => $packageA,
                'to' => $newPackageA,
        )));
    }

    public function testSolverAllJobs()
    {
        $this->repoInstalled->addPackage($packageD = $this->getPackage('D', '1.0'));
        $this->repoInstalled->addPackage($oldPackageC = $this->getPackage('C', '1.0'));

        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.1'));
        $this->repo->addPackage($this->getPackage('D', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), 'requires')));

        $this->reposComplete();

        $this->request->install('A');
        $this->request->install('C');
        $this->request->update('C');
        $this->request->remove('D');

        $this->checkSolverResult(array(
            array('job' => 'update',  'from' => $oldPackageC, 'to' => $packageC),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'remove',  'package' => $packageD),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverThreeAlternativeRequireAndConflict()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repo->addPackage($middlePackageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));
        $this->repo->addPackage($oldPackageB = $this->getPackage('B', '0.9'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), 'requires')));
        $packageA->setConflicts(array(new Link('A', 'B', $this->getVersionConstraint('<', '1.0'), 'conflicts')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $middlePackageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverObsolete()
    {
        $this->repoInstalled->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageB->setReplaces(array(new Link('B', 'A', null)));

        $this->reposComplete();

        $this->request->install('B');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $packageB),
        ));
    }

    public function testInstallOneOfTwoAlternatives()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('A', '1.0'));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallProvider()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '0.8'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageQ->setProvides(array(new Link('Q', 'B', $this->getVersionConstraint('=', '1.0'), 'provides')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageQ),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSkipReplacerOfExistingPackage()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageQ->setReplaces(array(new Link('Q', 'B', $this->getVersionConstraint('>=', '1.0'), 'replaces')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallReplacerOfMissingPackage()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageQ->setReplaces(array(new Link('Q', 'B', $this->getVersionConstraint('>=', '1.0'), 'replaces')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageQ),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSkipReplacedPackageIfReplacerIsSelected()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageQ->setReplaces(array(new Link('Q', 'B', $this->getVersionConstraint('>=', '1.0'), 'replaces')));

        $this->reposComplete();

        $this->request->install('A');
        $this->request->install('Q');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageQ),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testPickOlderIfNewerConflicts()
    {
        $this->repo->addPackage($packageX = $this->getPackage('X', '1.0'));
        $packageX->setRequires(array(
            new Link('X', 'A', $this->getVersionConstraint('>=', '2.0.0.0'), 'requires'),
            new Link('X', 'B', $this->getVersionConstraint('>=', '2.0.0.0'), 'requires')));

        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '2.1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '2.1.0'));

        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '2.0.0.0'), 'requires')));

        // new package A depends on version of package B that does not exist
        // => new package A is not installable
        $newPackageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '2.2.0.0'), 'requires')));

        // add a package S replacing both A and B, so that S and B or S and A cannot be simultaneously installed
        // but an alternative option for A and B both exists
        // this creates a more difficult so solve conflict
        $this->repo->addPackage($packageS = $this->getPackage('S', '2.0.0'));
        $packageS->setReplaces(array(new Link('S', 'A', $this->getVersionConstraint('>=', '2.0.0.0'), 'replaces'), new Link('S', 'B', $this->getVersionConstraint('>=', '2.0.0.0'), 'replaces')));

        $this->reposComplete();

        $this->request->install('X');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'install', 'package' => $newPackageB),
            array('job' => 'install', 'package' => $packageX),
        ));
    }

    public function testInstallCircularRequire()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB1 = $this->getPackage('B', '0.9'));
        $this->repo->addPackage($packageB2 = $this->getPackage('B', '1.1'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageB2->setRequires(array(new Link('B', 'A', $this->getVersionConstraint('>=', '1.0'), 'requires')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB2),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallAlternativeWithCircularRequire()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageB->setRequires(array(new Link('B', 'Virtual', $this->getVersionConstraint('>=', '1.0'), 'requires')));
        $packageC->setProvides(array(new Link('C', 'Virtual', $this->getVersionConstraint('==', '1.0'), 'provides')));
        $packageD->setProvides(array(new Link('D', 'Virtual', $this->getVersionConstraint('==', '1.0'), 'provides')));

        $packageC->setRequires(array(new Link('C', 'A', $this->getVersionConstraint('==', '1.0'), 'requires')));
        $packageD->setRequires(array(new Link('D', 'A', $this->getVersionConstraint('==', '1.0'), 'requires')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageC),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    /**
     * If a replacer D replaces B and C with C not otherwise available,
     * D must be installed instead of the original B.
     */
    public function testUseReplacerIfNecessary()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $this->repo->addPackage($packageD2 = $this->getPackage('D', '1.1'));

        $packageA->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'requires'),
            new Link('A', 'C', $this->getVersionConstraint('>=', '1.0'), 'requires'),
        ));

        $packageD->setReplaces(array(
            new Link('D', 'B', $this->getVersionConstraint('>=', '1.0'), 'replaces'),
            new Link('D', 'C', $this->getVersionConstraint('>=', '1.0'), 'replaces'),
        ));

        $packageD2->setReplaces(array(
            new Link('D', 'B', $this->getVersionConstraint('>=', '1.0'), 'replaces'),
            new Link('D', 'C', $this->getVersionConstraint('>=', '1.0'), 'replaces'),
        ));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageD2),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testIssue265()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '2.0.999999-dev'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.1-dev'));
        $this->repo->addPackage($packageA3 = $this->getPackage('A', '2.2-dev'));
        $this->repo->addPackage($packageB1 = $this->getPackage('B', '2.0.10'));
        $this->repo->addPackage($packageB2 = $this->getPackage('B', '2.0.9'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '2.0-dev'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '2.0.9'));

        $packageC->setRequires(array(
            new Link('C', 'A', $this->getVersionConstraint('>=', '2.0'), 'requires'),
            new Link('C', 'D', $this->getVersionConstraint('>=', '2.0'), 'requires'),
        ));

        $packageD->setRequires(array(
            new Link('D', 'A', $this->getVersionConstraint('>=', '2.1'), 'requires'),
            new Link('D', 'B', $this->getVersionConstraint('>=', '2.0-dev'), 'requires'),
        ));

        $packageB1->setRequires(array(new Link('B', 'A', $this->getVersionConstraint('==', '2.1.0.0-dev'), 'requires')));
        $packageB2->setRequires(array(new Link('B', 'A', $this->getVersionConstraint('==', '2.1.0.0-dev'), 'requires')));

        $packageB2->setReplaces(array(new Link('B', 'D', $this->getVersionConstraint('==', '2.0.9.0'), 'replaces')));

        $this->reposComplete();

        $this->request->install('C', $this->getVersionConstraint('==', '2.0.0.0-dev'));

        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');

        $this->solver->solve($this->request);
    }

    public function testConflictResultEmpty()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));;

        $packageA->setConflicts(array(
            new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), 'conflicts'),
        ));

        $this->reposComplete();

        $this->request->install('A');
        $this->request->install('B');

        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertEquals(1, count($problems));
            // TODO assert problem properties
        }
    }

    public function testUnsatisfiableRequires()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));

        $packageA->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('>=', '2.0'), 'requires'),
        ));

        $this->reposComplete();

        $this->request->install('A');

        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertEquals(1, count($problems));
            // TODO assert problem properties
        }
    }

    public function testLearnLiteralsWithSortedRuleLiterals()
    {
        $this->repo->addPackage($packageTwig2 = $this->getPackage('twig/twig', '2.0'));
        $this->repo->addPackage($packageTwig16 = $this->getPackage('twig/twig', '1.6'));
        $this->repo->addPackage($packageTwig15 = $this->getPackage('twig/twig', '1.5'));
        $this->repo->addPackage($packageSymfony = $this->getPackage('symfony/symfony', '2.0'));
        $this->repo->addPackage($packageTwigBridge = $this->getPackage('symfony/twig-bridge', '2.0'));

        $packageTwigBridge->setRequires(array(
            new Link('symfony/twig-bridge', 'twig/twig', $this->getVersionConstraint('<', '2.0'), 'requires'),
        ));

        $packageSymfony->setReplaces(array(
            new Link('symfony/symfony', 'symfony/twig-bridge', $this->getVersionConstraint('==', '2.0'), 'replaces'),
        ));

        $this->reposComplete();

        $this->request->install('symfony/twig-bridge');
        $this->request->install('twig/twig');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageTwig16),
            array('job' => 'install', 'package' => $packageTwigBridge),
        ));
    }

    public function testInstallRecursiveAliasDependencies()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));

        $packageA2->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('==', '2.0'), 'requires', '== 2.0'),
        ));
        $packageB->setRequires(array(
            new Link('B', 'A', $this->getVersionConstraint('>=', '2.0'), 'requires'),
        ));

        $this->repo->addPackage($packageA2Alias = $this->getAliasPackage($packageA2, '1.1'));

        $this->reposComplete();

        $this->request->install('A', $this->getVersionConstraint('==', '1.1.0.0'));

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA2),
            array('job' => 'install', 'package' => $packageA2Alias),
        ));
    }

    public function testInstallDevAlias()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));

        $packageB->setRequires(array(
            new Link('B', 'A', $this->getVersionConstraint('<', '2.0'), 'requires'),
        ));

        $this->repo->addPackage($packageAAlias = $this->getAliasPackage($packageA, '1.1'));

        $this->reposComplete();

        $this->request->install('A', $this->getVersionConstraint('==', '2.0'));
        $this->request->install('B');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageAAlias),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    protected function reposComplete()
    {
        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repo);
    }

    protected function checkSolverResult(array $expected)
    {
        $transaction = $this->solver->solve($this->request);

        $result = array();
        foreach ($transaction as $operation) {
            if ('update' === $operation->getJobType()) {
                $result[] = array(
                    'job'  => 'update',
                    'from' => $operation->getInitialPackage(),
                    'to'   => $operation->getTargetPackage()
                );
            } else {
                $job = ('uninstall' === $operation->getJobType() ? 'remove' : 'install');
                $result[] = array(
                    'job'     => $job,
                    'package' => $operation->getPackage()
                );
            }
        }

        $this->assertEquals($expected, $result);
    }
}
