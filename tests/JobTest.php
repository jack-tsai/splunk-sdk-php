<?php
/**
 * Copyright 2012 Splunk, Inc.
 * 
 * Licensed under the Apache License, Version 2.0 (the "License"): you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 * 
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

require_once 'SplunkTest.php';

class JobTest extends SplunkTest
{
    public function testGetTimeout()
    {
        list($service, $http) = $this->loginToMockService();
        
        // Get job
        $httpResponse = (object) array(
            'status' => 204,
            'reason' => 'No Content',
            'headers' => array(),
            'body' => '');
        $http->expects($this->atLeastOnce())
             ->method('get')
             ->will($this->returnValue($httpResponse));
        $job = $service->getJobs()->getReference('A_JOB');
        
        // Try to touch job when server refuses to return it
        try
        {
            $this->touch($job);
            $this->assertTrue(FALSE, 'Expected Splunk_HttpException to be thrown.');
        }
        catch (Splunk_HttpException $e)
        {
            $this->assertEquals(204, $e->getResponse()->status);
        }
    }
    
    public function testMakeReady()
    {
        $maxTries = 7;
        $this->assertTrue(
            $maxTries != Splunk_Job::DEFAULT_FETCH_MAX_TRIES,
            'This test is only valid for a non-default number of fetch attempts.');
        
        list($service, $http) = $this->loginToMockService();
        
        $httpResponse = (object) array(
            'status' => 204,
            'reason' => 'No Content',
            'headers' => array(),
            'body' => '');
        $http->expects($this->exactly($maxTries))
             ->method('get')
             ->will($this->returnValue($httpResponse));
        $job = $service->getJobs()->getReference('A_JOB');
        
        $this->assertFalse($job->isReady());
        try
        {
            $job->makeReady(/*maxTries=*/$maxTries, /*delayPerRetry=*/0.1);
            $this->assertTrue(FALSE, 'Expected Splunk_HttpException to be thrown.');
        }
        catch (Splunk_HttpException $e)
        {
            $this->assertEquals(204, $e->getResponse()->status);
        }
    }
    
    public function testMakeReadyReturnsSelf()
    {
        list($service, $http) = $this->loginToMockService();
        
        $httpResponse = (object) array(
            'status' => 200,
            'reason' => 'OK',
            'headers' => array(),
            'body' => '
<entry xmlns="http://www.w3.org/2005/Atom" xmlns:s="http://dev.splunk.com/ns/rest" xmlns:opensearch="http://a9.com/-/spec/opensearch/1.1/">
  <content type="text/xml">
  </content>
</entry>
');
        $http->expects($this->once())
             ->method('get')
             ->will($this->returnValue($httpResponse));
        $job = $service->getJobs()->getReference('A_JOB');
        
        $this->assertEquals($job, $job->makeReady());
    }
    
    public function testValidResultsForNormalJob()
    {
        $service = $this->loginToRealService();
        
        // (This search is installed by default on Splunk 4.x.)
        $ss = $service->getSavedSearches()->get('Top five sourcetypes');
        $job = $ss->dispatch();
        
        while (!$job->isDone())
        {
            //printf("%03.1f%%\r\n", $job->getProgress() * 100);
            usleep(0.1 * 1000000);
            $job->reload();
        }
        
        $resultsStream = $job->getResultsPage();
        $results = new Splunk_ResultsReader($resultsStream);
        
        // NOTE: Disabled because this is a brittle test.
        //       There might not be events with the "splunkd" or
        //       "splunkd_access" sourcetype immediately after Splunk
        //       is installed.
        /*
        $minExpectedSeriesNames = array('splunkd', 'splunkd_access');
        $actualSeriesNames = array();
        foreach ($results as $result)
            if (is_array($result))
                $actualSeriesNames[] = $result['series'];
        
        $remainingSeriesNames = 
            array_diff($minExpectedSeriesNames, $actualSeriesNames);
        $this->assertEmpty(
            $remainingSeriesNames,
            'Results are missing some expected series names: ' . 
                implode(',', $remainingSeriesNames));
        */
        
        $hasFieldOrder = FALSE;
        $hasAnyRows = FALSE;
        foreach ($results as $result)
        {
            if ($result instanceof Splunk_ResultsFieldOrder)
                $hasFieldOrder = TRUE;
            else if (is_array($result))
                $hasAnyRows = TRUE;
        }
        $this->assertTrue($hasFieldOrder,
            "Field order was not reported in the job results.");
        $this->assertTrue($hasAnyRows,
            "No rows were reported in the job results.");
    }
    
    /**
     * @expectedException Splunk_JobNotDoneException
     */
    public function testResultsNotDone()
    {
        $service = $this->loginToRealService();
        
        // (This search is installed by default on Splunk 4.x.)
        $ss = $service->getSavedSearches()->get('Top five sourcetypes');
        $job = $ss->dispatch();
        
        $this->assertFalse($job->isDone(),
            'Job completed too fast. Please rewrite this unit test to avoid timing issues.');
        
        $job->getResultsPage();
    }
    
    /**
     * @group slow
     */
    public function testPreview()
    {
        /* Setup */
        
        $service = $this->loginToRealService();
        
        $rtjob = $service->getJobs()->create('search index=_internal', array(
            'earliest_time' => 'rt',
            'latest_time' => 'rt',
        ));
        
        $this->assertTrue($rtjob['isRealTimeSearch'] === '1',
            'This should be a realtime job.');
        
        $this->assertTrue($rtjob['isPreviewEnabled'] === '1',
            'Preview should be automatically enabled for all realtime jobs. ' +
            'Otherwise there would be no way to get results from them.');
        
        /*
         * Subtest #1
         * 
         * Previews that don't have any results yet should report an empty
         * page of results (and not throw any exception).
         */
        
        $this->assertEquals(0, $rtjob['resultPreviewCount'],
            'Job yielded preview results too fast. ' .
            'Please rewrite this unit test to avoid timing issues.');
        
        // NOTE: Should NOT throw a Splunk_HttpException (HTTP 204)
        $page = $rtjob->getResultsPreviewPage();
        $this->assertFalse($this->pageHasResults($page),
            'Job claimed to have no preview results, yet results were obtained. ' .
            'This might indicate a timing issue in this unit test.');
        
        /*
         * Subtest #2
         * 
         * It should be possible to obtain preview results from a job
         * without that job being done generating results.
         */
        
        // Wait until some results...
        // (NOTE: This takes about 5 seconds on Splunk 4.3.2. A lot of time.)
        while ($rtjob['resultPreviewCount'] == 0)
        {
            usleep(0.2 * 1000000);
            $rtjob->reload();
        }
        
        // ...but not all
        $this->assertFalse($rtjob->isDone(),
            'Realtime job reported self as completed. ' .
            'Realtime jobs should never complete.');
        
        $page = $rtjob->getResultsPreviewPage();
        $this->assertTrue($this->pageHasResults($page),
            'Job claimed to have preview results, yet none were obtained.');
        
        /* Teardown */
        
        $rtjob->delete();
    }
    
    public function testControlActions()
    {
        /* Setup */
        
        $service = $this->loginToRealService();
        
        $rtjob = $service->getJobs()->create('search index=_internal', array(
            'earliest_time' => 'rt',
            'latest_time' => 'rt',
        ));
        
        $this->assertTrue($rtjob['isRealTimeSearch'] === '1',
            'This should be a realtime job.');
        
        /* Tests & Teardown */
        
        $rtjob->pause();
        $rtjob->reload();
        $this->assertEquals(1, $rtjob['isPaused']);
        
        $rtjob->unpause();
        $rtjob->reload();
        $this->assertEquals(0, $rtjob['isPaused']);
        
        $rtjob->finalize();
        $rtjob->reload();
        $this->assertEquals(1, $rtjob['isFinalized']);
        
        $rtjob->cancel();
        try
        {
            $rtjob->reload();
            $this->fail('Expected a cancelled job to be deleted.');
        }
        catch (Splunk_HttpException $e)
        {
            $this->assertEquals(404, $e->getResponse()->status);
        }
    }
    
    /**
     * Ensures that a job can be looked up by its reported name.
     * That is: $service->getJobs()->get($job->getName(), ...) == $job
     * 
     * NOTE: As currently written, this test actually invokes a lot of
     *       special-cased behavior beyond the core of what it is supposed to
     *       test. Therefore if multiple unit tests are failing, look at the
     *       others first.
     */
    public function testGetName()
    {
        $service = $this->loginToRealService();
        
        // (This search is installed by default on Splunk 4.x.)
        $ss = $service->getSavedSearches()->get('Top five sourcetypes');
        $job = $ss->dispatch();
        
        // Ensure that we have a fully loaded Job
        $this->touch($job);
        
        // Sanity check: Make sure reload is possible.
        // If reload breaks here then GET probably won't work.
        $job->reload();
        
        $job2 = $service->getJobs()->get($job->getName(), $job->getNamespace());
        $this->assertEquals($job->getName(), $job2->getName(),
            'Fetching a job by its own name returned a different job.');
    }
    
    public function testCreateInCustomNamespace()
    {
        $postResponse = (object) array(
            'status' => 200,
            'reason' => 'OK',
            'headers' => array(),
            'body' => trim("
<?xml version='1.0' encoding='UTF-8'?>
<response><sid>1345584253.35</sid></response>
"));
        $postArgs = array(
            // (The URL should correspond to the namespace)
            'https://localhost:8089/servicesNS/USER/APP/search/jobs/',
            array(
                'search' => 'A_SEARCH',
            ),
            array(
                'Authorization' => 'Splunk ' . SplunkTest::MOCK_SESSION_TOKEN,
            ),
        );
        
        list($service, $http) = $this->loginToMockService(
            $postResponse,
            $postArgs);
        
        $namespace = Splunk_Namespace::createUser('USER', 'APP');
        $job = $service->getJobs()->create('A_SEARCH', array(
            'namespace' => $namespace,
        ));
        // (The created object should be in the correct namespace)
        $this->assertEquals($namespace, $job->getNamespace());
    }
    
    // === Utility ===
    
    private function pageHasResults($resultsPage)
    {
        $pageHasResults = FALSE;
        foreach (new Splunk_ResultsReader($resultsPage) as $result)
            $pageHasResults = TRUE;
        return $pageHasResults;
    }
}
