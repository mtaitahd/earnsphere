<?php
/**
 * EarnSphere - Referral Contest API
 * Public standings + logged-in user rank.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/Contest.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

$action     = $_GET['action'] ?? 'standings';
$contestId  = (int) ($_GET['contest_id'] ?? 0);

switch ($action) {
    case 'standings':
        $contest = $contestId > 0
            ? Contest::getContestById($contestId)
            : Contest::getFeaturedContest();

        if (!$contest) {
            jsonResponse(['success' => false, 'error' => 'No contest available'], 404);
        }

        $limit     = min(max((int) ($_GET['limit'] ?? 20), 1), 100);
        $standings = Contest::getStandings($contest['id'], $limit);
        $winners   = Contest::getWinners($contest['id']);

        $ranked = [];
        foreach ($standings as $s) {
            $ranked[] = [
                'position' => $s['position'],
                'user_id'  => $s['user_id'],
                'name'     => $s['name'],
                'count'    => $s['count'],
            ];
        }

        $maskedWinners = [];
        foreach ($winners as $w) {
            $maskedWinners[] = [
                'position'        => $w['position'],
                'name'            => Contest::maskName($w['full_name'] ?? null),
                'amount'          => (float) $w['amount'],
                'referrals_count' => (int) $w['referrals_count'],
                'awarded_at'      => $w['created_at'],
            ];
        }

        $userRank = null;
        if (Auth::isLoggedIn()) {
            $userRank = Contest::getUserRank($contest['id'], (int) $_SESSION['user_id']);
        }

        jsonResponse([
            'success' => true,
            'contest' => [
                'id'            => (int) $contest['id'],
                'title'         => $contest['title'],
                'description'   => $contest['description'],
                'start_date'    => $contest['start_date'],
                'end_date'      => $contest['end_date'],
                'prize1'        => (float) $contest['prize1'],
                'prize2'        => (float) $contest['prize2'],
                'prize3'        => (float) $contest['prize3'],
                'min_referrals' => (int) $contest['min_referrals'],
                'status'        => $contest['status'],
            ],
            'standings' => $ranked,
            'winners'   => $maskedWinners,
            'user_rank' => $userRank,
        ]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
