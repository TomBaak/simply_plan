<?php

	namespace App\Controller\Student;
	
	use App\Entity\Afspraak;
	use App\Entity\Student;
	use App\Entity\Uitnodiging;
	use DateTime;
	use Doctrine\ORM\EntityManagerInterface;
	use Symfony\Bridge\Doctrine\Form\Type\EntityType;
	use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
	use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
	use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
	use Symfony\Component\Form\Extension\Core\Type\TextType;
	use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Session\SessionInterface;
    use Symfony\Component\Mailer\Bridge\Google\Transport\GmailSmtpTransport;
	use Symfony\Component\Mailer\Mailer;
	use Symfony\Component\Mime\Email;
	use Symfony\Component\Routing\Annotation\Route;
	use App\Classes\Utilities as Utils;
	use Symfony\Component\OptionsResolver\OptionsResolver;
	use Symfony\Component\Validator\Constraints\Choice;
	
	class StudentController extends AbstractController
	{
		
		private $session;
		
		public function __construct(SessionInterface $session)
		{
			
			$this->session = $session;
			
		}
		
		/**
		 * @Route("/student/afspraak", name="afspraak")
		 */
		public function afspraak(Request $request, EntityManagerInterface $em)
		{
			
			$uitnodiging = $this->getDoctrine()->getRepository(Uitnodiging::class)->findOneBy([
				
				'invitationCode' => $request->get('id')
			
			]);
			
			if ($uitnodiging === NULL) {
				
				$this->addFlash('error', 'Er ging iets mis probeer het opnieuw. Als dit probleem zich herhaalt neem dan contact op met je SLBer');
				
				return $this->redirectToRoute('home');
				
			}
			
			$pickedTimes = [];
			
			$afpraken = $uitnodiging->getAfspraken();
			
			for ($i = 0; $i < count($afpraken); $i++) {
				
				if (array_search($afpraken[$i], $pickedTimes) === false) {
					array_push($pickedTimes, $afpraken[$i]->getTime());
				}
				
			}
			
			if (count($uitnodiging->getKlas()->getStudents()) == count($pickedTimes)) {
				$this->addFlash('error', 'Er zijn meer plaatsen beschikbaar op deze datum. Neem contact op met je SLBer');
				
				return $this->redirectToRoute('home');
			}
			
			$times = [];
			
			//creates an array of times with interval of 15 minutes between the start time and end time
//            in probably the most inefficient way possible
			do {
				
				if (count($times) > 0) {
					
					$index = count($times) - 1;
					
					$lastTime = new DateTime($uitnodiging->getStopTime()->format('Ymd') . $times[$index]->format('His'));
					
					$time = $lastTime->modify('+15 minutes');
					
					if (array_search($time, $pickedTimes) === false) {
						$times[] = $time;
					}
					
				} else {
					
					$timeObj = new DateTime($uitnodiging->getStopTime()->format('Ymd') . $uitnodiging->getStartTime()->format('H:i'));
					
					while (array_search($timeObj, $pickedTimes) !== false) {
						$timeObj->modify('+15 minutes');
					}
					
					array_push($times, $timeObj);
					
				}
				
			} while ($times[count($times) - 1] < $uitnodiging->getStopTime());
			
			$afspraakEmpty = new Afspraak();
			
			$student[] = $this->getDoctrine()->getRepository(Student::class)->findOneBy([
				
				'studentId' => $request->get('student')
				
			]);
			
			$form = $this->createFormBuilder($afspraakEmpty)
				->add('time', ChoiceType::class, [
					'choices' => $times,
					'choice_label' => function ($choice, $key, $value) {
						
						return $choice->format('H:i');
					},
					'label' => 'Tijd',
					'required' => true,
					'help' => 'Dit zijn de tijden die nog beschikbaar zijn'
				])
				->add('student', ChoiceType::class, [
					'choices' => $student,
					'choice_label' => function ($choice) {
						return $choice->getNaam() . ' - ' . $choice->getStudentId();
					},
					'required' => true,
					'disabled' => 'disabled'
				])
				->add('phoneNumber', TextType::class, [
					'label' => 'Telefoonnummer:',
					'required' => true
					
				])
				->add('with_parents', CheckboxType::class, [
					'label' => 'Ik kom met mijn ouders',
					'required' => false,
					'help' => 'Studenten onder de 18 zijn verplicht met hun ouders aanwezig te zijn!'
				])
				->getForm();
			
			$form->handleRequest($request);
			if ($form->isSubmitted() && $form->isValid()) {
				
				$afspraak = $form->getData();
				
				$gemaakteAfspraak = $this->getDoctrine()->getRepository(Afspraak::class)->findOneBy([
					
					'student' => $afspraak->getStudent()->getId(),
					'uitnodiging' => $uitnodiging
					
				]);
				
				if($gemaakteAfspraak !== NULL){
					
					$this->addFlash('error', 'Deze student heeft al een afspraak gemaakt');
					
					return $this->redirectToRoute('afspraak',array('id' => $uitnodiging->getInvitationCode()));
					
				}
				
				$afspraak->setUitnodiging($uitnodiging);
				
				try{
					$em->persist($afspraak);
					$em->flush();
				}catch (\Exception $e){
					error_log($e->getMessage(),0);
					
					$this->addFlash('error', 'Er ging iets mis tijdens het aanmaken van uw afspraak probeer het alstublieft nog eens');
					
					return $this->redirectToRoute('afspraak',array('id' => $uitnodiging->getInvitationCode()));
				}
				
				$email = (new Email())
					->from('tomdevelop@gmail.com')
					->priority(Email::PRIORITY_HIGH)
					->subject('Afspraak ouder gesprek')
					->html('<h1 style="font-weight: bold">Afspraak ouder gesprek</h1>' . '<p>U heeft een afspraak gemaakt op '
						. $uitnodiging->getDate()->format('d M Y') . ' om ' . $afspraak->getTime()->format('H:i')
						. ' met meneer/mevrouw ' . $uitnodiging->getKlas()->getSLB()->getLastname());
				
				$email->addTo($afspraak->getStudent()->getStudentId() . '@student.rocmondriaan.nl');
				
				$transport = new GmailSmtpTransport('tomdeveloping@gmail.com', 'TDevelop20032002');
				$mailer = new Mailer($transport);
				$mailer->send($email);
				
				$this->addFlash('success', 'Afspraak is gemaakt, u kunt de pagina nu verlaten');
				
				return $this->redirectToRoute('home');
				
			}
			
			
			return $this->render('student/student_afspraak.html.twig', [
				
				'uitnodiging' => $uitnodiging,
				'form' => $form->createView()
			
			]);
			
		}
		
	}
